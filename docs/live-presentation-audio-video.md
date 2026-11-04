# Live presentation audio and video

Live presentations support an optional interactive audio/video call. Each teacher and student must explicitly choose **Start mic & camera** or **Join audio/video** and grant browser permission. Camera and microphone tracks can be toggled independently, and either person can leave the call. Media is not recorded by Akademi.

The call uses browser WebRTC connections, with the teacher's browser acting as the classroom hub and relaying opted-in participant streams so everyone in the call can see and hear one another. The teacher's device therefore receives and forwards participant media; Akademi does not record it. This small-group design is not a substitute for an SFU for large classes. The default ICE configuration uses Google's public STUN service to discover network routes. STUN servers can observe the connecting device's public IP address. To use a different STUN server, set `PRESENTATION_STUN_URL` in the application environment.

For reliable calls across restrictive firewalls and carrier-grade NAT, configure a TURN server with Coturn's shared-secret authentication:

```dotenv
PRESENTATION_STUN_URL=stun:your-stun.example:3478
PRESENTATION_TURN_URL=turn:turn.example:3478?transport=udp,turns:turn.example:5349?transport=tcp
PRESENTATION_TURN_SECRET=your-private-coturn-static-auth-secret
```

Keep the TURN secret only in the server environment. Akademi derives one-hour TURN credentials for the browser; it never sends the shared secret to participants. Leave both TURN settings unset if no TURN service is configured. The site must use HTTPS for camera and microphone access.

This browser-based hub is intended for small-group calls. Larger classrooms should use a dedicated SFU media server; increasing the number of participants increases the teacher device's network and processing load.
