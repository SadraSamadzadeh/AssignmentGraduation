// Define the two different data structures

export interface TrackingMatch {
  id: number;
  name: string;
  teamName: string;
  startTime: string; // ISO string
  endTime: string;   // ISO string
  avgTotalTimeActive: string;
  activities: {
    id: number;
    name: string;
    startTime: string;
    endTime: string;
  }[];
}

export interface VideoMatch {
  id: string;
  club: {
    id: string;
    name: string;
  };
  home: {
    name: string;
  };
  away: {
    name: string;
  };
  starting_at: {
    date: string;
  };
  stopping_at: {
    date: string;
  };
  timezone: string;
}

// Define the comparison result
export interface MatchScoreResult {
  score: number;
  confidence: "Unlikely" | "Possible" | "Likely" | "Confident";
  details: Record<string, number | string>;
}

// Utility to parse durations
function getDurationHours(start: string, end: string): number {
  const s = new Date(start).getTime();
  const e = new Date(end).getTime();
  return (e - s) / (1000 * 60 * 60);
}

// Compute score function
export function compareTrackingAndVideo(
  tracking: TrackingMatch,
  video: VideoMatch
): MatchScoreResult {
  let score = 0;
  const details: Record<string, number | string> = {};

  // ---- 1️⃣ Date proximity ----
  const trackingStart = new Date(tracking.startTime);
  const videoStart = new Date(video.starting_at.date);
  const dayDiff = Math.abs(trackingStart.getTime() - videoStart.getTime()) / (1000 * 60 * 60 * 24);
  const dateScore = dayDiff <= 1 ? 25 : dayDiff <= 3 ? 10 : 0;
  score += dateScore;
  details["dateScore"] = dateScore;

  // ---- 2️⃣ Team name matching ----
  const trackingTeam = tracking.teamName.toLowerCase();
  const videoHome = video.home.name.toLowerCase();
  const videoAway = video.away.name.toLowerCase();
  let teamScore = 0;
  if (trackingTeam.includes(videoHome) || trackingTeam.includes(videoAway)) {
    teamScore = 25;
  } else if (video.club.name.toLowerCase().includes(trackingTeam)) {
    teamScore = 10;
  }
  score += teamScore;
  details["teamScore"] = teamScore;

  // ---- 3️⃣ Duration similarity ----
  const trackingDuration = getDurationHours(tracking.startTime, tracking.endTime);
  const videoDuration = getDurationHours(video.starting_at.date, video.stopping_at.date);
  const durationDiff = Math.abs(trackingDuration - videoDuration);
  const durationScore = durationDiff < 0.5 ? 15 : durationDiff < 1 ? 10 : 0;
  score += durationScore;
  details["durationScore"] = durationScore;

  // ---- 4️⃣ Club match ----
  const clubMatch =
    video.club.name.toLowerCase().includes(tracking.teamName.toLowerCase()) ? 10 : 0;
  score += clubMatch;
  details["clubScore"] = clubMatch;

  // ---- 5️⃣ Temporal ordering (close in time same week) ----
  const temporalScore = dayDiff <= 7 ? 5 : 0;
  score += temporalScore;
  details["temporalScore"] = temporalScore;

  // ---- 6️⃣ Assume data completeness ----
  const dataCompleteness = tracking.activities?.length > 0 ? 10 : 0;
  score += dataCompleteness;
  details["dataCompleteness"] = dataCompleteness;

  // ---- 7️⃣ Confidence label ----
  let confidence: MatchScoreResult["confidence"];
  if (score >= 80) confidence = "Confident";
  else if (score >= 60) confidence = "Likely";
  else if (score >= 40) confidence = "Possible";
  else confidence = "Unlikely";

  return { score, confidence, details };
}
// const trackingMatch: TrackingMatch = {
//   id: 193,
//   name: "Match Capelle - Westlandia",
//   teamName: "Capelle 1",
//   startTime: "2025-11-01T11:51:09.100Z",
//   endTime: "2025-11-01T20:53:00.900Z",
//   avgTotalTimeActive: "01:43:09.8849999",
//   activities: [
//     {
//       id: 426,
//       name: "Match",
//       startTime: "02:42:52",
//       endTime: "04:42:30",
//     },
//   ],
// };

// const videoMatch: VideoMatch = {
//   id: "76bff137-f8a4-4c1f-989c-264f3f9b083f",
//   club: { id: "c0eab1e2-8769-4d57-a00b-e537de17fca1", name: "VV Capelle" },
//   home: { name: "VV Capelle" },
//   away: { name: "Roda JC" },
//   starting_at: { date: "2025-10-28T20:00:00+01:00" },
//   stopping_at: { date: "2025-10-28T21:54:00+01:00" },
//   timezone: "Europe/Amsterdam",
// };
// const result = compareTrackingAndVideo(trackingMatch, videoMatch);

// console.log("Match comparison result:");
// console.log(result);