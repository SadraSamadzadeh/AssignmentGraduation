import {
  compareTrackingAndVideo,
  getDurationHours,
  MatchScoreResult,
  TrackingMatch,
  VideoMatch,
} from "./MatchingAlgorithm";

interface MatchBuffers {
  tracking: Map<string, TrackingMatch[]>;
  video: Map<string, VideoMatch[]>;
}

const BUCKET_INTERVAL_MINUTES = 60;
const RETENTION_MS = 2 * 60 * 60 * 1000; // 2 hours
const MATCH_THRESHOLD = 60;

function getBucketKey(date: string): string {
  // Normalize to UTC first to handle different timezone formats consistently
  const d = new Date(date);
  
  // Check if date is valid
  if (isNaN(d.getTime())) {
    console.warn(`Invalid date provided to getBucketKey: ${date}`);
    return new Date().toISOString();
  }
  
  // Round to nearest bucket interval
  d.setMinutes(Math.floor(d.getMinutes() / BUCKET_INTERVAL_MINUTES) * BUCKET_INTERVAL_MINUTES, 0, 0);
  return d.toISOString();
}

function normalizeName(name: string): string {
  return name
    .toLowerCase()
    .replace(/\b(vv|fc|sc|1|2|3|jo\d+|team\d+)\b/g, "") // remove numbers/extra suffixes
    .replace(/[^a-z0-9]+/g, "")
    .trim();
}

function isPotentialMatch(t: TrackingMatch, v: VideoMatch): boolean {
  try {
    // Use timezone-aware date parsing
    const trackingStart = new Date(t.startTime);
    const videoStart = new Date(v.starting_at.date);
    
    // Check for valid dates
    if (isNaN(trackingStart.getTime()) || isNaN(videoStart.getTime())) {
      console.warn("Invalid dates in isPotentialMatch", { 
        tracking: t.startTime, 
        video: v.starting_at.date 
      });
      return false;
    }
    
    // More flexible time window (extended to 7 days for better matching)
    const hoursDiff = Math.abs(trackingStart.getTime() - videoStart.getTime()) / (1000 * 60 * 60);
    if (hoursDiff > 7 * 24) return false; // Within 7 days

    // Use improved name matching
    const tName = normalizeName(t.teamName);
    const vClub = normalizeName(v.club.name);
    const vHome = normalizeName(v.home.name);
    const vAway = normalizeName(v.away.name);

    // More flexible name matching
    const nameOverlap =
      tName.includes(vClub) ||
      vClub.includes(tName) ||
      tName.includes(vHome) ||
      vHome.includes(tName) ||
      tName.includes(vAway) ||
      vAway.includes(tName);
      
    if (!nameOverlap) return false;

    // More flexible duration matching (up to 8 hours difference)
    const trackingEnd = new Date(t.endTime);
    const videoEnd = new Date(v.stopping_at.date);
    
    if (isNaN(trackingEnd.getTime()) || isNaN(videoEnd.getTime())) {
      return true; // If we can't check duration, rely on name and time matching
    }
    
    const trackingDuration = (trackingEnd.getTime() - trackingStart.getTime()) / (1000 * 60 * 60);
    const videoDuration = (videoEnd.getTime() - videoStart.getTime()) / (1000 * 60 * 60);
    const durationDiff = Math.abs(trackingDuration - videoDuration);
    
    return durationDiff < 8; // Allow up to 8 hours difference
  } catch (error) {
    console.warn("Error in isPotentialMatch:", error);
    return false;
  }
}

function logSection(title: string) {
  console.log(`\n\x1b[36m=== ${title} ===\x1b[0m`);
}

function logInfo(message: string) {
  console.log(`\x1b[32m[INFO]\x1b[0m ${message}`);
}

function logWarn(message: string) {
  console.log(`\x1b[33m[WARN]\x1b[0m ${message}`);
}

function logError(message: string) {
  console.log(`\x1b[31m[ERROR]\x1b[0m ${message}`);
}

function logDebug(label: string, value: any) {
  console.log(`\x1b[90m[DEBUG] ${label}: ${JSON.stringify(value)}\x1b[0m`);
}

export class MatchEngine {
  private buffers: MatchBuffers = {
    tracking: new Map(),
    video: new Map(),
  };

  constructor(private onMatch: (t: TrackingMatch, v: VideoMatch, r: MatchScoreResult) => void) {
    logInfo("MatchEngine initialized");
  }

  handleTrackingMessage(t: TrackingMatch) {
    const bucket = getBucketKey(t.startTime);
    logSection(`📡 Incoming Tracking Message: ${t.teamName} (${bucket})`);
    logDebug("Tracking payload", t);

    this.tryMatch(t, this.buffers.video, "tracking");
    this.addToBuffer(this.buffers.tracking, bucket, t);
  }

  handleVideoMessage(v: VideoMatch) {
    const bucket = getBucketKey(v.starting_at.date);
    logSection(`🎥 Incoming Video Message: ${v.home.name} vs ${v.away.name} (${bucket})`);
    logDebug("Video payload", v);

    this.tryMatch(v, this.buffers.tracking, "video");
    this.addToBuffer(this.buffers.video, bucket, v);
  }

  private tryMatch(
    incoming: TrackingMatch | VideoMatch,
    oppositeIndex: Map<string, any[]>,
    type: "tracking" | "video"
  ) {
    const date =
      type === "tracking"
        ? (incoming as TrackingMatch).startTime
        : (incoming as VideoMatch).starting_at.date;

    const bucket = getBucketKey(date);
    const nearbyBuckets = this.getNearbyBuckets(bucket);
    let best: { other: any; result: MatchScoreResult } | null = null;

    logInfo(
      `🔍 Attempting to match ${type === "tracking" ? "Tracking" : "Video"} message within nearby buckets`
    );
    logDebug("Nearby buckets", nearbyBuckets);

    for (const b of nearbyBuckets) {
      const candidates = oppositeIndex.get(b) ?? [];
      if (candidates.length === 0) continue;

      logInfo(`Comparing against ${candidates.length} candidate(s) in bucket ${b}`);

      for (const candidate of candidates) {
        if (type === "tracking") {
          const t = incoming as TrackingMatch;
          const v = candidate as VideoMatch;
          if (!isPotentialMatch(t, v)) {
            logDebug("Skipped candidate (no potential match)", { tracking: t.id, video: v.id });
            continue;
          }
          const result = compareTrackingAndVideo(t, v);
          logDebug("Match comparison result", result);

          if (!best || result.score > best.result.score) best = { other: v, result };
        } else {
          const v = incoming as VideoMatch;
          const t = candidate as TrackingMatch;
          if (!isPotentialMatch(t, v)) {
            logDebug("Skipped candidate (no potential match)", { tracking: t.id, video: v.id });
            continue;
          }
          const result = compareTrackingAndVideo(t, v);
          logDebug("Match comparison result", result);

          if (!best || result.score > best.result.score) best = { other: t, result };
        }
      }
    }

    if (best && best.result.score >= MATCH_THRESHOLD) {
      logInfo(
        `✅ Match found with score ${best.result.score} (${best.result.confidence})`
      );
      this.onMatch(
        type === "tracking" ? (incoming as TrackingMatch) : (best.other as TrackingMatch),
        type === "video" ? (incoming as VideoMatch) : (best.other as VideoMatch),
        best.result
      );
      this.removeFromBuffer(oppositeIndex, best.other);
    } else if (best) {
      logWarn(`No strong match found (best score: ${best.result.score})`);
    } else {
      logWarn("No potential matches found");
    }
  }

  private getNearbyBuckets(bucketKey: string): string[] {
    const base = new Date(bucketKey).getTime();
    const delta = BUCKET_INTERVAL_MINUTES * 60 * 1000;
    return [
      new Date(base - delta).toISOString(),
      bucketKey,
      new Date(base + delta).toISOString(),
    ];
  }

  private addToBuffer(map: Map<string, any[]>, bucket: string, value: any) {
    if (!map.has(bucket)) map.set(bucket, []);
    map.get(bucket)!.push({ ...value, __receivedAt: Date.now() });
    logInfo(`Added message to buffer ${bucket} (type: ${value.name || value.teamName})`);
  }

  private removeFromBuffer(map: Map<string, any[]>, value: any) {
    for (const [bucket, list] of map.entries()) {
      const filtered = list.filter((v: any) => v.id !== value.id);
      if (filtered.length !== list.length) {
        map.set(bucket, filtered);
        logInfo(`🧹 Removed matched entry from buffer ${bucket} (id=${value.id})`);
      }
    }
  }

  cleanup() {
    const now = Date.now();
    logSection("🧽 Running cleanup job...");
    for (const map of [this.buffers.tracking, this.buffers.video] as Map<string, any[]>[]) {
      for (const [bucket, list] of map.entries()) {
        const fresh = list.filter((v: any) => now - v.__receivedAt < RETENTION_MS);
        if (fresh.length > 0) map.set(bucket, fresh);
        else {
          map.delete(bucket);
          logInfo(`🗑️ Cleared expired bucket ${bucket}`);
        }
      }
    }
  }
}
