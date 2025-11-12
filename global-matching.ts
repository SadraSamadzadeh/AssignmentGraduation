import { MatchEngine } from "./MatchService.js";
import { TrackingMatch, VideoMatch, MatchScoreResult, compareTrackingAndVideo } from "./MatchingAlgorithm.js";

// Generate a global ID for matched pairs
function generateGlobalMatchId(): string {
  return `MATCH_${Date.now()}_${Math.random().toString(36).substr(2, 9)}`;
}

// Combined match result interface
interface GlobalMatch {
  globalId: string;
  matchScore: number;
  confidence: string;
  trackingData: TrackingMatch;
  videoData: VideoMatch;
  matchDetails: Record<string, number | string>;
  timestamp: string;
}

// Multiple tracking data objects
const trackingMatches: TrackingMatch[] = [
  // Tracking Match 1 - October 16th Training
  {
    id: 184,
    name: "Training Capelle 1",
    teamName: "Capelle 1",
    startTime: "2025-10-16T17:30:13.300Z",
    endTime: "2025-10-16T23:59:59.800Z",
    avgTotalTimeActive: "01:06:08.5472727",
    activities: [
      {
        id: 396,
        name: "Warming - up",
        startTime: "00:40:01",
        endTime: "00:52:25",
      },
      {
        id: 397,
        name: "Passing",
        startTime: "00:53:41",
        endTime: "01:04:29",
      },
      {
        id: 398,
        name: "Positioning game",
        startTime: "01:09:20",
        endTime: "01:27:27",
      },
      {
        id: 399,
        name: "Small - sided game",
        startTime: "01:32:42",
        endTime: "01:47:30",
      },
    ],
  },
  
  // Tracking Match 2 - October 23rd Training
  {
    id: 187,
    name: "Training Capelle 1",
    teamName: "Capelle 1",
    startTime: "2025-10-23T17:48:54.800Z",
    endTime: "2025-10-23T19:39:30.900Z",
    avgTotalTimeActive: "01:07:22.0100000",
    activities: [
      {
        id: 407,
        name: "Warming - up",
        startTime: "00:20:26",
        endTime: "00:30:40",
      },
      {
        id: 408,
        name: "Klein positiespel",
        startTime: "00:31:51",
        endTime: "00:41:30",
      },
      {
        id: 409,
        name: "11 vs 11",
        startTime: "00:46:14",
        endTime: "01:28:49",
      },
    ],
  },
];

// Multiple video data objects
const videoMatches: VideoMatch[] = [
  // Video Match 1 - October 16th Recording (should match tracking 1)
  {
    id: "e5652e87-3409-4907-90d8-e95343014452",
    club: { 
      id: "c0eab1e2-8769-4d57-a00b-e537de17fca1", 
      name: "VV Capelle"
    },
    home: { 
      name: "VV Capelle" 
    },
    away: { 
      name: "VV Capelle" 
    },
    starting_at: { date: "2025-10-16T20:25:00+02:00" },
    stopping_at: { date: "2025-10-16T20:59:00+02:00" },
    timezone: "Europe/Amsterdam",
  },
  
  // Video Match 2 - October 24th Recording (should match tracking 2)
  {
    id: "5de73d7c-3bff-405c-b679-1725ec4b0674",
    club: { 
      id: "c0eab1e2-8769-4d57-a00b-e537de17fca1", 
      name: "VV Capelle"
    },
    home: { 
      name: "VV Capelle" 
    },
    away: { 
      name: "VV Capelle" 
    },
    starting_at: { date: "2025-10-24T18:30:00+02:00" },
    stopping_at: { date: "2025-10-24T19:54:00+02:00" },
    timezone: "Europe/Amsterdam",
  },
];

// Global matching system
class GlobalMatchingSystem {
  private matches: GlobalMatch[] = [];
  private processedTrackingIds: Set<number> = new Set();
  private processedVideoIds: Set<string> = new Set();

  processAllData(trackingData: TrackingMatch[], videoData: VideoMatch[]): GlobalMatch[] {
    console.log("🔄 Processing all incoming data for global matching...");
    console.log(`📊 Tracking matches: ${trackingData.length}`);
    console.log(`🎬 Video matches: ${videoData.length}`);
    
    // Match engine for scoring
    const engine = new MatchEngine(() => {}); // We'll handle matches manually
    
    // Try to match each tracking with each video
    for (const tracking of trackingData) {
      if (this.processedTrackingIds.has(tracking.id)) continue;
      
      let bestMatch: {
        video: VideoMatch;
        result: MatchScoreResult;
      } | null = null;
      
      for (const video of videoData) {
        if (this.processedVideoIds.has(video.id)) continue;
        
        // Use the imported comparison function
        const result = compareTrackingAndVideo(tracking, video);
        
        // Check if this is a valid match (threshold of 60)
        if (result.score >= 60) {
          if (!bestMatch || result.score > bestMatch.result.score) {
            bestMatch = { video, result };
          }
        }
      }
      
      // If we found a good match, create a global match object
      if (bestMatch) {
        const globalMatch: GlobalMatch = {
          globalId: generateGlobalMatchId(),
          matchScore: bestMatch.result.score,
          confidence: bestMatch.result.confidence,
          trackingData: tracking,
          videoData: bestMatch.video,
          matchDetails: bestMatch.result.details,
          timestamp: new Date().toISOString(),
        };
        
        this.matches.push(globalMatch);
        this.processedTrackingIds.add(tracking.id);
        this.processedVideoIds.add(bestMatch.video.id);
        
        console.log(`✅ GLOBAL MATCH CREATED!`);
        console.log(`🆔 Global ID: ${globalMatch.globalId}`);
        console.log(`📊 Score: ${globalMatch.matchScore}/100`);
        console.log(`🎪 Confidence: ${globalMatch.confidence}`);
        console.log(`📋 Tracking: ${tracking.name} (ID: ${tracking.id})`);
        console.log(`🎬 Video: ${bestMatch.video.home.name} vs ${bestMatch.video.away.name} (ID: ${bestMatch.video.id})`);
        console.log(`⏰ Matched at: ${globalMatch.timestamp}`);
        console.log("📈 Match Details:", globalMatch.matchDetails);
        console.log("=".repeat(80));
      }
    }
    
    // Report unmatched data
    const unmatchedTracking = trackingData.filter(t => !this.processedTrackingIds.has(t.id));
    const unmatchedVideo = videoData.filter(v => !this.processedVideoIds.has(v.id));
    
    if (unmatchedTracking.length > 0) {
      console.log("⚠️  UNMATCHED TRACKING DATA:");
      unmatchedTracking.forEach(t => {
        console.log(`   📋 ${t.name} (ID: ${t.id}) - ${t.startTime}`);
      });
    }
    
    if (unmatchedVideo.length > 0) {
      console.log("⚠️  UNMATCHED VIDEO DATA:");
      unmatchedVideo.forEach(v => {
        console.log(`   🎬 ${v.home.name} vs ${v.away.name} (ID: ${v.id}) - ${v.starting_at.date}`);
      });
    }
    
    return this.matches;
  }
  
  getMatches(): GlobalMatch[] {
    return this.matches;
  }
  
  getMatchById(globalId: string): GlobalMatch | undefined {
    return this.matches.find(m => m.globalId === globalId);
  }
}

// Run the global matching system
function runGlobalMatchingExample() {
  console.log("🚀 Starting Global Matching System");
  console.log("=" + "=".repeat(50));
  
  const globalSystem = new GlobalMatchingSystem();
  
  // Process all data at once
  const globalMatches = globalSystem.processAllData(trackingMatches, videoMatches);
  
  // Summary
  console.log(`\n📊 FINAL SUMMARY:`);
  console.log(`✅ Total Global Matches Created: ${globalMatches.length}`);
  console.log(`📋 Tracking Data Processed: ${trackingMatches.length}`);
  console.log(`🎬 Video Data Processed: ${videoMatches.length}`);
  
  if (globalMatches.length > 0) {
    console.log(`\n🎯 GLOBAL MATCHES:`);
    globalMatches.forEach((match, index) => {
      console.log(`${index + 1}. ${match.globalId} (Score: ${match.matchScore}, Confidence: ${match.confidence})`);
    });
  }
  
  return globalMatches;
}

// Export for external use
export { GlobalMatch, GlobalMatchingSystem, runGlobalMatchingExample };

// Always run the example when this file is executed directly
runGlobalMatchingExample();