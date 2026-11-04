<?php
declare(strict_types=1);

header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="app-base-path" content="<?= e(app_base_path()) ?>">
  <meta name="theme-color" content="#143c32">
  <title>Follow presentation · Akademi</title>
  <link rel="icon" href="<?= e(app_url('assets/icon.svg')) ?>" type="image/svg+xml">
  <link rel="stylesheet" href="<?= e(app_url('assets/fontawesome/css/fontawesome.min.css')) ?>">
  <link rel="stylesheet" href="<?= e(app_url('assets/fontawesome/css/solid.min.css')) ?>">
  <link rel="stylesheet" href="<?= e(app_url('assets/action-icons.css')) ?>">
  <link rel="stylesheet" href="<?= e(app_url('assets/styles.css?v=170')) ?>">
  <link rel="stylesheet" href="<?= e(app_url('assets/presentation-follow.css?v=43')) ?>">
  <link rel="stylesheet" href="<?= e(app_url('assets/presentation-call.css?v=3')) ?>">
  <script src="<?= e(app_url('assets/presentation-call.js?v=3')) ?>" defer></script>
  <script src="<?= e(app_url('assets/presentation-follow.js?v=37')) ?>" defer></script>
  <script src="<?= e(app_url('assets/action-icons.js?v=1')) ?>" defer></script>
</head>
<body class="presentation-follow-page">
  <main class="presentation-follow-shell">
    <header class="presentation-follow-header">
      <a class="presentation-follow-brand" href="<?= e(app_url('')) ?>" aria-label="Akademi home">AKADEMI</a>
      <p data-follow-status role="status" aria-live="polite">Connecting to your teacher…</p>
    </header>
    <div class="presentation-follow-progress" aria-label="Presentation progress"><span data-follow-progress></span></div>
    <section class="presentation-follow-main" aria-live="polite">
      <section class="presentation-follow-join" data-follow-join aria-labelledby="follow-join-title">
        <div class="presentation-follow-join-card">
          <span class="presentation-follow-join-icon" aria-hidden="true">👋</span>
          <h1 id="follow-join-title">Welcome to class</h1>
          <p>Enter your name so your teacher can recognize your poll answers and chat messages.</p>
          <form data-follow-join-form>
            <label for="follow-student-name">Your name</label>
            <input id="follow-student-name" name="name" maxlength="80" autocomplete="name" required placeholder="e.g. Ama">
            <button type="submit"><i class="fa-solid fa-arrow-right-to-bracket" aria-hidden="true"></i> Join presentation</button>
          </form>
        </div>
      </section>
      <div class="presentation-follow-experience" data-follow-experience hidden>
        <div class="presentation-follow-stage" data-follow-stage>
          <div class="presentation-follow-placeholder">Waiting for the teacher to start the presentation…</div>
        </div>
        <section class="presentation-call presentation-call-student" data-follow-call aria-label="Live audio and video">
          <div class="presentation-call-heading">
            <div><strong>Live audio &amp; video</strong><p data-follow-call-status>Camera and microphone are off. Join when you are ready.</p></div>
            <div class="presentation-call-controls">
              <button type="button" data-follow-call-enable><i class="fa-solid fa-video" aria-hidden="true"></i> Join audio/video</button>
              <button type="button" data-follow-call-mic hidden aria-pressed="true"><i class="fa-solid fa-microphone" aria-hidden="true"></i> Mute mic</button>
              <button type="button" data-follow-call-camera hidden aria-pressed="true"><i class="fa-solid fa-camera" aria-hidden="true"></i> Turn camera off</button>
              <button type="button" data-follow-call-leave hidden><i class="fa-solid fa-phone-slash" aria-hidden="true"></i> Leave call</button>
            </div>
          </div>
          <div class="presentation-call-grid" data-follow-call-grid></div>
        </section>
        <footer class="presentation-follow-footer">
          <span data-follow-title>Live classroom presentation</span>
          <div class="presentation-follow-toolbar">
            <strong data-follow-timer aria-live="off"></strong>
            <strong data-follow-count aria-live="polite"></strong>
            <button type="button" data-follow-dialog-open="poll" hidden><i class="fa-solid fa-square-poll-vertical" aria-hidden="true"></i> Poll</button>
            <button type="button" data-follow-dialog-open="pace"><i class="fa-solid fa-gauge-high" aria-hidden="true"></i> Class pace <span class="presentation-follow-counter" data-follow-pace-count hidden></span></button>
            <button type="button" data-follow-dialog-open="chat"><i class="fa-solid fa-comments" aria-hidden="true"></i> Class chat <span class="presentation-follow-counter" data-follow-question-count hidden></span></button>
            <button type="button" data-follow-dialog-open="direct"><i class="fa-solid fa-user-group" aria-hidden="true"></i> Private chat <span class="presentation-follow-counter" data-follow-direct-count hidden></span></button>
            <button type="button" class="presentation-follow-fullscreen" data-follow-fullscreen aria-label="Enter full screen" aria-pressed="false"><i class="fa-solid fa-expand" aria-hidden="true"></i><span>Full screen</span></button>
          </div>
        </footer>
        <dialog class="presentation-follow-dialog" data-follow-dialog="poll" aria-labelledby="follow-poll-title">
          <header><h2 id="follow-poll-title">Class poll</h2><button type="button" data-follow-dialog-close aria-label="Close poll"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></header>
          <section class="presentation-follow-poll" data-follow-poll hidden aria-live="polite"></section>
        </dialog>
        <dialog class="presentation-follow-dialog" data-follow-dialog="pace" aria-labelledby="follow-pace-title">
          <header><h2 id="follow-pace-title">Class pace</h2><button type="button" data-follow-dialog-close aria-label="Close class pace"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></header>
          <nav class="presentation-follow-reactions" id="follow-reactions" data-follow-reactions aria-label="Classroom pace responses" hidden></nav>
        </dialog>
        <dialog class="presentation-follow-dialog presentation-follow-chat-dialog" data-follow-dialog="chat" aria-labelledby="follow-chat-title">
          <header><h2 id="follow-chat-title">Class chat</h2><button type="button" data-follow-dialog-close aria-label="Close class chat"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></header>
          <div class="presentation-follow-chat-list" data-follow-questions></div>
          <form class="presentation-follow-chat-form" data-follow-question-form>
            <label for="follow-student-question">Message your teacher</label>
            <textarea id="follow-student-question" name="question" maxlength="1000" required rows="2" placeholder="Write a message…"></textarea>
            <button type="submit"><i class="fa-solid fa-paper-plane" aria-hidden="true"></i> Send message</button>
          </form>
        </dialog>
        <dialog class="presentation-follow-dialog presentation-follow-chat-dialog presentation-follow-direct-dialog" data-follow-dialog="direct" aria-labelledby="follow-direct-title">
          <header><h2 id="follow-direct-title">Private chat</h2><button type="button" data-follow-dialog-close aria-label="Close private chat"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></header>
          <label class="presentation-follow-direct-recipient" for="follow-direct-recipient">Chat with
            <select id="follow-direct-recipient" data-follow-direct-recipient></select>
          </label>
          <div class="presentation-follow-chat-list" data-follow-direct-messages aria-live="polite"></div>
          <p class="presentation-follow-direct-status" data-follow-direct-status role="status"></p>
          <form class="presentation-follow-chat-form" data-follow-direct-form>
            <label for="follow-direct-message">Private message</label>
            <textarea id="follow-direct-message" name="message" maxlength="1000" required rows="2" placeholder="Write a private message…"></textarea>
            <button type="submit"><i class="fa-solid fa-paper-plane" aria-hidden="true"></i> Send privately</button>
          </form>
        </dialog>
      </div>
      <dialog class="presentation-follow-dialog presentation-call-prompt" data-follow-call-prompt aria-labelledby="follow-call-prompt-title">
        <header><h2 id="follow-call-prompt-title">Join the live audio/video call?</h2></header>
        <p>Your microphone and camera will stay off unless you choose to join. If you join, your audio and video will be shared with the teacher and connected classmates. You can mute, turn off your camera, or leave at any time.</p>
        <div class="presentation-call-controls">
          <button type="button" data-follow-call-prompt-join><i class="fa-solid fa-video" aria-hidden="true"></i> Turn on mic and camera</button>
          <button type="button" class="presentation-call-secondary" data-follow-call-prompt-skip>Continue without audio/video</button>
        </div>
      </dialog>
      <p class="presentation-follow-notification" data-follow-direct-notification role="status" aria-live="polite"></p>
    </section>
  </main>
</body>
</html>
