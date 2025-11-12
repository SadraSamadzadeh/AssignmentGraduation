import { MatchEngine } from "./MatchService";
import { TrackingMatch, VideoMatch } from "./MatchingAlgorithm";

// New tracking data from your dashboard
const trackingMatch: TrackingMatch = {
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
};

// New video data from your dashboard
const videoMatch: VideoMatch = {
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
};

// Instantiate the match engine and register callback
const engine = new MatchEngine((t, v, result) => {
  console.log("✅ Matched!");
  console.log("Tracking:", t.name);
  console.log("Video:", v.home.name, "vs", v.away.name);
  console.log("Result:", result);
});

// Simulate message arrival order (video first, then tracking later)
console.log("📩 Sending video message...");
engine.handleVideoMessage(videoMatch);

console.log("📩 Sending tracking message...");
engine.handleTrackingMessage(trackingMatch);

// Optionally test delayed cleanup
setTimeout(() => {
  engine.cleanup();
  console.log("🧹 Cleanup complete");
}, 2000);
