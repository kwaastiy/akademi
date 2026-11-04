(() => {
  "use strict";

  class PresentationCall {
    constructor(options) {
      this.options = options;
      this.role = options.role;
      this.peers = new Map();
      this.participants = [];
      this.localStream = null;
      this.remoteStreams = new Map();
      this.remoteTiles = new Map();
      this.remoteNames = new Map();
      this.relayedStreams = new Set();
      this.iceServers = [{ urls: "stun:stun.l.google.com:19302" }];
      this.running = false;
      this.pollTimer = null;
      this.polling = false;
      this.localTile = null;
      this.sendQueue = Promise.resolve();
    }

    start() {
      if (this.running) return;
      this.running = true;
      void this.pollSignals();
    }

    setParticipants(participants) {
      this.participants = Array.isArray(participants) ? participants.filter((person) => person.online) : [];
      if (this.role !== "teacher" || !this.localStream) return;
      this.participants.forEach((person) => {
        if (!this.peers.has(String(person.id))) void this.createPeer(String(person.id), String(person.name || "Student"), true);
      });
    }

    async startMedia() {
      if (!navigator.mediaDevices?.getUserMedia) {
        throw new Error("Camera and microphone access requires a supported browser and a secure HTTPS connection.");
      }
      if (!this.localStream || !this.localStream.getTracks().some((track) => track.readyState === "live")) {
        this.localTile?.remove();
        this.localTile = null;
        this.localStream?.getTracks().forEach((track) => track.stop());
        const audio = { echoCancellation: true, noiseSuppression: true };
        const video = { facingMode: "user", width: { ideal: 640 }, height: { ideal: 360 } };
        try {
          this.localStream = await navigator.mediaDevices.getUserMedia({ audio, video });
        } catch (bothError) {
          try {
            this.localStream = await navigator.mediaDevices.getUserMedia({ audio: true, video: false });
            this.options.onStatus?.("Camera unavailable; joined with microphone only.");
          } catch (audioError) {
            try {
              this.localStream = await navigator.mediaDevices.getUserMedia({ audio: false, video });
              this.options.onStatus?.("Microphone unavailable; joined with camera only.");
            } catch (videoError) {
              const details = [bothError, audioError, videoError]
                .filter((error) => error instanceof Error)
                .map((error) => error.message);
              throw new Error(`Camera and microphone access failed. Check browser permissions and connected devices.${details.length ? ` ${details.at(-1)}` : ""}`);
            }
          }
        }
        this.localStream.getTracks().forEach((track) => track.addEventListener("ended", () => this.updateLocalMediaState()));
        this.renderLocalVideo();
        this.updateLocalMediaState();
      }
      try {
        this.start();
        for (const [peerId, peer] of this.peers) {
          await this.attachLocalTracks(peer);
          if (this.role === "teacher") await this.makeOffer(peerId, peer);
        }
        if (this.role === "student") {
          await this.sendSignal("ready", null);
        } else {
          this.participants.forEach((person) => {
            const peerId = String(person.id);
            if (!this.peers.has(peerId)) void this.createPeer(peerId, String(person.name || "Student"), true);
            else void this.makeOffer(peerId, this.peers.get(peerId));
          });
        }
      } catch (error) {
        this.localStream?.getTracks().forEach((track) => track.stop());
        this.localStream = null;
        this.localTile?.remove();
        this.localTile = null;
        this.updateLocalMediaState();
        throw error;
      }
      return this.getLocalMediaState();
    }

    setAudioEnabled(enabled) {
      const tracks = (this.localStream?.getAudioTracks() || []).filter((track) => track.readyState === "live");
      tracks.forEach((track) => { track.enabled = Boolean(enabled); });
      this.updateLocalMediaState();
      return tracks.length > 0;
    }

    setVideoEnabled(enabled) {
      const tracks = (this.localStream?.getVideoTracks() || []).filter((track) => track.readyState === "live");
      tracks.forEach((track) => { track.enabled = Boolean(enabled); });
      this.localTile?.toggleAttribute("data-camera-off", !enabled && tracks.length > 0);
      this.updateLocalMediaState();
      return tracks.length > 0;
    }

    getLocalMediaState() {
      return {
        audio: Boolean(this.localStream?.getAudioTracks().some((track) => track.readyState === "live")),
        video: Boolean(this.localStream?.getVideoTracks().some((track) => track.readyState === "live"))
      };
    }

    updateLocalMediaState() {
      const state = this.getLocalMediaState();
      this.localTile?.toggleAttribute("data-camera-off", !state.video);
      this.options.onMediaState?.(state);
    }

    async pollSignals() {
      if (!this.running || this.polling || document.hidden) {
        if (this.running) this.schedulePoll();
        return;
      }
      this.polling = true;
      try {
        const url = this.role === "teacher"
          ? `${this.options.basePath}/api/teacher/presentation-simulations/${encodeURIComponent(this.options.simulationId)}/call-signals`
          : `${this.options.basePath}/api/presentation-simulations/${encodeURIComponent(this.options.token)}/call-signals?participant_id=${encodeURIComponent(this.options.participantId)}`;
        const response = await fetch(url, {
          credentials: "same-origin",
          cache: "no-store",
          headers: { Accept: "application/json" }
        });
        const result = await response.json();
        if (!response.ok) throw new Error(result.error || "Call connection could not be refreshed.");
        if (Array.isArray(result.ice_servers) && result.ice_servers.length) this.iceServers = result.ice_servers;
        for (const signal of (Array.isArray(result.signals) ? result.signals : [])) {
          await this.handleSignal(signal);
        }
      } catch (error) {
        this.options.onStatus?.(error instanceof Error ? `Call reconnecting: ${error.message}` : "The call is reconnecting.");
      } finally {
        this.polling = false;
        this.schedulePoll();
      }
    }

    schedulePoll() {
      if (!this.running) return;
      if (this.pollTimer !== null) window.clearTimeout(this.pollTimer);
      this.pollTimer = window.setTimeout(() => void this.pollSignals(), document.hidden ? 3000 : 650);
    }

    async handleSignal(signal) {
      const peerId = this.role === "teacher" ? String(signal.from_id || "") : "teacher";
      if (signal.signal_type === "ready" && this.role === "teacher") {
        const person = this.participants.find((item) => String(item.id) === peerId);
        const name = String(signal.from_name || person?.name || "Student");
        if (!this.peers.has(peerId)) await this.createPeer(peerId, name, true);
        return;
      }
      if (!peerId) return;
      if (signal.signal_type === "hangup") {
        this.closePeer(peerId);
        return;
      }
      let peer = this.peers.get(peerId);
      if (signal.signal_type === "offer" && !peer) {
        const person = this.participants.find((item) => String(item.id) === peerId);
        peer = await this.createPeer(peerId, this.role === "teacher" ? String(person?.name || "Student") : "Teacher", false);
      }
      if (!peer) return;
      const pc = peer.connection;
      if (signal.signal_type === "offer") {
        const collision = peer.makingOffer || pc.signalingState !== "stable";
        const polite = this.role === "student";
        if (collision && !polite) return;
        if (collision) await pc.setLocalDescription({ type: "rollback" });
        await pc.setRemoteDescription(signal.payload);
        await this.addPendingCandidates(peer);
        const answer = await pc.createAnswer();
        await pc.setLocalDescription(answer);
        await this.sendSignal("answer", pc.localDescription, peerId);
      } else if (signal.signal_type === "answer" && pc.signalingState === "have-local-offer") {
        await pc.setRemoteDescription(signal.payload);
        await this.addPendingCandidates(peer);
      } else if (signal.signal_type === "candidate") {
        const candidate = signal.payload;
        if (!candidate) return;
        if (pc.remoteDescription) {
          await pc.addIceCandidate(candidate);
        } else {
          peer.pendingCandidates.push(candidate);
        }
      } else if (signal.signal_type === "stream_name" && this.role === "student"
        && typeof signal.payload?.stream_id === "string" && typeof signal.payload?.name === "string") {
        this.remoteNames.set(signal.payload.stream_id, signal.payload.name);
        const tile = this.remoteTiles.get(signal.payload.stream_id);
        if (tile) tile.querySelector("span").textContent = signal.payload.name;
      }
    }

    async addPendingCandidates(peer) {
      const candidates = peer.pendingCandidates.splice(0);
      for (const candidate of candidates) await peer.connection.addIceCandidate(candidate);
    }

    async createPeer(peerId, name, offer) {
      if (this.peers.has(peerId)) return this.peers.get(peerId);
      const connection = new RTCPeerConnection({ iceServers: this.iceServers });
      const peer = { connection, name, pendingCandidates: [], makingOffer: false };
      this.peers.set(peerId, peer);
      connection.addEventListener("icecandidate", (event) => {
        if (event.candidate) void this.sendSignal("candidate", event.candidate.toJSON(), peerId).catch(() => {});
      });
      connection.addEventListener("track", (event) => {
        const stream = event.streams[0] || new MediaStream([event.track]);
        const streamName = this.remoteNames.get(stream.id) || peer.name;
        this.remoteStreams.set(stream.id, { peerId, stream, name: streamName });
        this.renderRemoteStream(stream, streamName, peerId);
        if (this.role === "teacher") {
          void this.relayStream(peerId, stream, streamName).catch((error) => {
            this.options.onStatus?.(error instanceof Error ? error.message : "Participant audio/video could not be shared.");
          });
        }
        stream.getTracks().forEach((track) => track.addEventListener("ended", () => {
          void this.removeRemoteTrack(stream.id, track).catch((error) => {
            this.options.onStatus?.(error instanceof Error ? error.message : "A participant's media could not be disconnected.");
          });
        }));
      });
      connection.addEventListener("connectionstatechange", () => {
        for (const [streamId, remote] of this.remoteStreams) {
          if (remote.peerId === peerId) {
            const tile = this.remoteTiles.get(streamId);
            if (tile) tile.dataset.connection = connection.connectionState;
          }
        }
        if (connection.connectionState === "failed" || connection.connectionState === "closed") {
          this.options.onStatus?.(`${name} call connection ${connection.connectionState}.`);
        }
      });
      connection.addEventListener("negotiationneeded", () => void this.makeOffer(peerId, peer));
      if (offer && this.role === "teacher" && !this.localStream) {
        connection.addTransceiver("audio", { direction: "recvonly" });
        connection.addTransceiver("video", { direction: "recvonly" });
      }
      await this.attachLocalTracks(peer);
      if (this.role === "teacher") {
        for (const [streamId, remote] of this.remoteStreams) {
          if (remote.peerId !== peerId && !this.relayedStreams.has(`${streamId}:${peerId}`)) {
            await this.relayStreamToPeer(remote.peerId, remote.stream, remote.name, peerId);
          }
        }
      }
      if (offer) await this.makeOffer(peerId, peer);
      return peer;
    }

    renderRemoteStream(stream, name, peerId) {
      if (!this.options.grid) return;
      let tile = this.remoteTiles.get(stream.id);
      if (!tile) {
        tile = this.createTile(name, false);
        tile.dataset.peerId = peerId;
        this.remoteTiles.set(stream.id, tile);
        this.options.grid.append(tile);
      }
      const video = tile.querySelector("video");
      stream.getVideoTracks().forEach((track) => {
        const updateCameraState = () => tile.toggleAttribute("data-camera-off", track.muted || !track.enabled || track.readyState === "ended");
        track.addEventListener("mute", updateCameraState);
        track.addEventListener("unmute", updateCameraState);
        track.addEventListener("ended", updateCameraState);
        updateCameraState();
      });
      if (video.srcObject !== stream) {
        video.srcObject = stream;
        void video.play().catch(() => {
          this.options.onStatus?.(`Tap ${name}'s video tile to enable audio playback.`);
        });
      }
      tile.querySelector("span").textContent = name;
    }

    async relayStream(sourcePeerId, stream, name) {
      for (const targetPeerId of this.peers.keys()) {
        if (targetPeerId !== sourcePeerId) {
          await this.relayStreamToPeer(sourcePeerId, stream, name, targetPeerId);
        }
      }
    }

    async relayStreamToPeer(sourcePeerId, stream, name, targetPeerId) {
      const key = `${stream.id}:${targetPeerId}`;
      const target = this.peers.get(targetPeerId);
      if (!target || targetPeerId === sourcePeerId) return;
      if (!this.relayedStreams.has(key)) {
        this.relayedStreams.add(key);
        await this.sendSignal("stream_name", { stream_id: stream.id, name }, targetPeerId);
      }
      stream.getTracks().forEach((track) => {
        if (!target.connection.getSenders().some((sender) => sender.track?.id === track.id)) {
          target.connection.addTrack(track, stream);
        }
      });
    }

    async removeRemoteTrack(streamId, track) {
      const remote = this.remoteStreams.get(streamId);
      if (!remote) return;
      if (this.role === "teacher") {
        for (const [peerId, peer] of this.peers) {
          if (peerId === remote.peerId) continue;
          const sender = peer.connection.getSenders().find((item) => item.track?.id === track.id);
          if (sender) await sender.replaceTrack(null);
          this.relayedStreams.delete(`${streamId}:${peerId}`);
        }
      }
      if (remote.stream.getTracks().every((item) => item.readyState === "ended")) {
        this.remoteStreams.delete(streamId);
        this.remoteTiles.get(streamId)?.remove();
        this.remoteTiles.delete(streamId);
        this.remoteNames.delete(streamId);
      }
    }

    async attachLocalTracks(peer) {
      if (!this.localStream) return;
      for (const track of this.localStream.getTracks()) {
        if (peer.connection.getSenders().some((sender) => sender.track?.id === track.id)) continue;
        const transceiver = peer.connection.getTransceivers().find((item) =>
          item.receiver.track.kind === track.kind && item.sender.track === null
        );
        if (transceiver) {
          await transceiver.sender.replaceTrack(track);
          transceiver.direction = "sendrecv";
        } else {
          peer.connection.addTrack(track, this.localStream);
        }
      }
    }

    async makeOffer(peerId, peer) {
      const pc = peer.connection;
      if (pc.signalingState !== "stable" || peer.makingOffer) return;
      try {
        peer.makingOffer = true;
        const offer = await pc.createOffer();
        if (pc.signalingState !== "stable") return;
        await pc.setLocalDescription(offer);
        await this.sendSignal("offer", pc.localDescription, peerId);
      } catch (error) {
        this.options.onStatus?.(error instanceof Error ? `Could not connect ${peer.name}: ${error.message}` : `Could not connect ${peer.name}.`);
      } finally {
        peer.makingOffer = false;
      }
    }

    sendSignal(type, payload, peerId = "teacher") {
      const request = this.sendQueue.then(async () => {
        const url = this.role === "teacher"
          ? `${this.options.basePath}/api/teacher/presentation-simulations/${encodeURIComponent(this.options.simulationId)}/call-signals/${encodeURIComponent(peerId)}`
          : `${this.options.basePath}/api/presentation-simulations/${encodeURIComponent(this.options.token)}/call-signals`;
        const body = this.role === "teacher"
          ? { signal_type: type, payload }
          : { participant_id: this.options.participantId, signal_type: type, payload };
        const headers = { Accept: "application/json", "Content-Type": "application/json" };
        if (this.role === "teacher") headers["X-CSRF-Token"] = this.options.csrf;
        const response = await fetch(url, {
          method: "POST",
          credentials: "same-origin",
          headers,
          body: JSON.stringify(body)
        });
        const result = await response.json();
        if (!response.ok) throw new Error(result.error || "Call setup could not be sent.");
      });
      this.sendQueue = request.catch((error) => {
        this.options.onStatus?.(error instanceof Error ? error.message : "Call setup could not be sent.");
      });
      return request;
    }

    createTile(name, local) {
      const tile = document.createElement("article");
      tile.className = `presentation-call-tile${local ? " presentation-call-tile-local" : ""}`;
      const video = document.createElement("video");
      video.autoplay = true;
      video.playsInline = true;
      video.muted = local;
      video.setAttribute("aria-label", `${local ? "Your" : name + "'s"} camera`);
      if (!local) video.addEventListener("click", () => void video.play().catch(() => {}));
      const label = document.createElement("span");
      label.textContent = local ? `${name} · You` : name;
      const cameraStatus = document.createElement("small");
      cameraStatus.className = "presentation-call-camera-status";
      cameraStatus.textContent = "Camera off";
      tile.append(video, label, cameraStatus);
      return tile;
    }

    renderLocalVideo() {
      if (!this.options.grid || this.localTile) return;
      this.localTile = this.createTile(this.options.name || "You", true);
      this.localTile.querySelector("video").srcObject = this.localStream;
      this.options.grid.prepend(this.localTile);
    }

    closePeer(peerId) {
      const peer = this.peers.get(peerId);
      if (!peer) return;
      peer.connection.close();
      this.peers.delete(peerId);
      for (const [streamId, remote] of this.remoteStreams) {
        if (remote.peerId === peerId) {
          this.remoteTiles.get(streamId)?.remove();
          this.remoteTiles.delete(streamId);
          this.remoteStreams.delete(streamId);
        }
      }
      for (const key of this.relayedStreams) {
        if (key.endsWith(`:${peerId}`)) this.relayedStreams.delete(key);
      }
    }

    async leave() {
      const peerIds = Array.from(this.peers.keys());
      this.localStream?.getTracks().forEach((track) => track.stop());
      this.localStream = null;
      this.localTile?.remove();
      this.localTile = null;
      this.options.onMediaState?.({ audio: false, video: false });
      peerIds.forEach((peerId) => this.closePeer(peerId));
      await Promise.all(peerIds.map(async (peerId) => {
        try {
          await this.sendSignal("hangup", null, peerId);
        } catch (error) {
          this.options.onStatus?.(error instanceof Error ? error.message : "The call could not be closed cleanly.");
        }
      }));
      this.options.onStatus?.("You left the audio/video call. Your camera and microphone are off.");
    }

    stop() {
      this.running = false;
      if (this.pollTimer !== null) window.clearTimeout(this.pollTimer);
      this.pollTimer = null;
      void this.leave();
    }
  }

  window.PresentationCall = { create: (options) => new PresentationCall(options) };
})();
