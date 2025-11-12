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
export function getDurationHours(start: string, end: string): number {
  const s = new Date(start).getTime();
  const e = new Date(end).getTime();
  return (e - s) / (1000 * 60 * 60);
}

// Timezone normalization utilities
export function normalizeToUTC(dateString: string): Date {
  try {
    // Handle different date formats and convert to UTC
    const date = new Date(dateString);
    
    // Check if the date is valid
    if (isNaN(date.getTime())) {
      throw new Error(`Invalid date: ${dateString}`);
    }
    
    return date;
  } catch (error) {
    console.warn(`Failed to parse date: ${dateString}`, error);
    // Return current date as fallback
    return new Date();
  }
}

export function normalizeTimestamp(tracking: TrackingMatch, video: VideoMatch): {
  trackingStart: Date;
  trackingEnd: Date;
  videoStart: Date;
  videoEnd: Date;
} {
  return {
    trackingStart: normalizeToUTC(tracking.startTime),
    trackingEnd: normalizeToUTC(tracking.endTime),
    videoStart: normalizeToUTC(video.starting_at.date),
    videoEnd: normalizeToUTC(video.stopping_at.date),
  };
}

// Improved name normalization and matching utilities
export function normalizeTeamName(name: string): string {
  return name
    .toLowerCase()
    .replace(/\b(vv|fc|sc|sv|roda|jc|ajax|psv|az|fc)\b/g, "") // Remove common club prefixes/suffixes
    .replace(/[^a-z0-9]+/g, " ") // Replace special characters with spaces
    .trim()
    .replace(/\s+/g, " "); // Normalize whitespace
}

export function extractKeywords(name: string): string[] {
  const normalized = normalizeTeamName(name);
  return normalized.split(" ").filter(word => word.length > 2); // Only keep words longer than 2 chars
}

export function calculateNameSimilarity(name1: string, name2: string): number {
  const keywords1 = extractKeywords(name1);
  const keywords2 = extractKeywords(name2);
  
  if (keywords1.length === 0 || keywords2.length === 0) return 0;
  
  let matches = 0;
  let totalComparisons = 0;
  
  // Check for exact keyword matches
  for (const word1 of keywords1) {
    for (const word2 of keywords2) {
      totalComparisons++;
      if (word1 === word2) {
        matches++;
      } else if (word1.includes(word2) || word2.includes(word1)) {
        matches += 0.7; // Partial match
      } else if (calculateLevenshteinDistance(word1, word2) <= 2 && Math.min(word1.length, word2.length) > 3) {
        matches += 0.5; // Fuzzy match for longer words
      }
    }
  }
  
  return totalComparisons > 0 ? (matches / Math.max(keywords1.length, keywords2.length)) : 0;
}

export function calculateLevenshteinDistance(str1: string, str2: string): number {
  const matrix = [];
  
  for (let i = 0; i <= str2.length; i++) {
    matrix[i] = [i];
  }
  
  for (let j = 0; j <= str1.length; j++) {
    matrix[0][j] = j;
  }
  
  for (let i = 1; i <= str2.length; i++) {
    for (let j = 1; j <= str1.length; j++) {
      if (str2.charAt(i - 1) === str1.charAt(j - 1)) {
        matrix[i][j] = matrix[i - 1][j - 1];
      } else {
        matrix[i][j] = Math.min(
          matrix[i - 1][j - 1] + 1,
          matrix[i][j - 1] + 1,
          matrix[i - 1][j] + 1
        );
      }
    }
  }
  
  return matrix[str2.length][str1.length];
}

// Compute score function
export function compareTrackingAndVideo(
  tracking: TrackingMatch,
  video: VideoMatch
): MatchScoreResult {
  let score = 0;
  const details: Record<string, number | string> = {};

  // ---- 🕐 Normalize timestamps for comparison ----
  const normalizedTimes = normalizeTimestamp(tracking, video);
  details["trackingStartUTC"] = normalizedTimes.trackingStart.toISOString();
  details["videoStartUTC"] = normalizedTimes.videoStart.toISOString();

  // ---- 1️⃣ Enhanced Date proximity with timezone normalization ----
  const timeDiffMs = Math.abs(
    normalizedTimes.trackingStart.getTime() - normalizedTimes.videoStart.getTime()
  );
  const dayDiff = timeDiffMs / (1000 * 60 * 60 * 24);
  const hourDiff = timeDiffMs / (1000 * 60 * 60);
  
  let dateScore = 0;
  if (hourDiff <= 2) {
    dateScore = 25; // Very close in time (same event)
  } else if (hourDiff <= 6) {
    dateScore = 20; // Same session/day
  } else if (dayDiff <= 1) {
    dateScore = 15; // Within same day
  } else if (dayDiff <= 3) {
    dateScore = 10; // Within 3 days
  } else if (dayDiff <= 7) {
    dateScore = 5; // Within a week
  }
  
  score += dateScore;
  details["dateScore"] = dateScore;
  details["hourDiff"] = Math.round(hourDiff * 100) / 100;
  details["dayDiff"] = Math.round(dayDiff * 100) / 100;

  // ---- 2️⃣ Enhanced Team name matching ----
  const trackingTeam = tracking.teamName;
  const videoHome = video.home.name;
  const videoAway = video.away.name;
  const videoClub = video.club.name;
  
  // Calculate similarity scores
  const homeTeamSimilarity = calculateNameSimilarity(trackingTeam, videoHome);
  const awayTeamSimilarity = calculateNameSimilarity(trackingTeam, videoAway);
  const clubSimilarity = calculateNameSimilarity(trackingTeam, videoClub);
  
  // Determine the best match and assign scores
  const maxTeamSimilarity = Math.max(homeTeamSimilarity, awayTeamSimilarity);
  let teamScore = 0;
  
  if (maxTeamSimilarity >= 0.8) {
    teamScore = 25; // Strong match
  } else if (maxTeamSimilarity >= 0.6) {
    teamScore = 20; // Good match
  } else if (maxTeamSimilarity >= 0.4) {
    teamScore = 15; // Moderate match
  } else if (clubSimilarity >= 0.6) {
    teamScore = 12; // Club name match
  } else if (clubSimilarity >= 0.4) {
    teamScore = 8; // Weak club match
  } else if (maxTeamSimilarity >= 0.2) {
    teamScore = 5; // Minimal match
  }
  
  score += teamScore;
  details["teamScore"] = teamScore;
  details["homeTeamSimilarity"] = Math.round(homeTeamSimilarity * 100) / 100;
  details["awayTeamSimilarity"] = Math.round(awayTeamSimilarity * 100) / 100;
  details["clubSimilarity"] = Math.round(clubSimilarity * 100) / 100;

  // ---- 3️⃣ Enhanced Duration similarity with normalized times ----
  const trackingDurationHours = (normalizedTimes.trackingEnd.getTime() - normalizedTimes.trackingStart.getTime()) / (1000 * 60 * 60);
  const videoDurationHours = (normalizedTimes.videoEnd.getTime() - normalizedTimes.videoStart.getTime()) / (1000 * 60 * 60);
  const durationDiff = Math.abs(trackingDurationHours - videoDurationHours);
  
  let durationScore = 0;
  if (durationDiff < 0.25) {
    durationScore = 15; // Very similar duration
  } else if (durationDiff < 0.5) {
    durationScore = 12; // Close duration
  } else if (durationDiff < 1) {
    durationScore = 8; // Somewhat similar
  } else if (durationDiff < 2) {
    durationScore = 5; // Different but reasonable
  }
  
  score += durationScore;
  details["durationScore"] = durationScore;
  details["trackingDurationHours"] = Math.round(trackingDurationHours * 100) / 100;
  details["videoDurationHours"] = Math.round(videoDurationHours * 100) / 100;
  details["durationDiffHours"] = Math.round(durationDiff * 100) / 100;

  // ---- 4️⃣ Additional club context match ----
  // This is already covered in the enhanced team matching above
  // But we can add extra points for strong club association
  const additionalClubScore = clubSimilarity >= 0.8 ? 5 : 0;
  score += additionalClubScore;
  details["additionalClubScore"] = additionalClubScore;

  // ---- 5️⃣ Enhanced Temporal ordering with overlapping time bonus ----
  let temporalScore = 0;
  
  // Check if times overlap or are very close
  const trackingStart = normalizedTimes.trackingStart.getTime();
  const trackingEnd = normalizedTimes.trackingEnd.getTime();
  const videoStart = normalizedTimes.videoStart.getTime();
  const videoEnd = normalizedTimes.videoEnd.getTime();
  
  // Check for time overlap
  const hasOverlap = trackingStart <= videoEnd && videoStart <= trackingEnd;
  
  if (hasOverlap) {
    temporalScore = 10; // Perfect overlap
  } else if (hourDiff <= 1) {
    temporalScore = 8; // Very close timing
  } else if (hourDiff <= 3) {
    temporalScore = 6; // Same session
  } else if (dayDiff <= 1) {
    temporalScore = 4; // Same day
  } else if (dayDiff <= 7) {
    temporalScore = 2; // Same week
  }
  
  score += temporalScore;
  details["temporalScore"] = temporalScore;
  details["hasTimeOverlap"] = hasOverlap ? "yes" : "no";

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