# YRB-Radio
24/7 Funky Fresh OpenSource Cultural Radio 
Welcome to the under-the-hood breakdown for the **YRB Radio** web player (`[yrbmag.com/radio](https://yrbmag.com/radio)`). If you're curious about how we're pumping those crisp freeform streams straight into a clean, minimalist dark-mode interface without any heavy bloat, here is the technical rundown of how `index.html` and the underlying scripts make it all tick.

---

## ⚡ What is YRB Radio?

At its core, YRB Radio is a custom-built, lightweight web streaming client designed to match a raw, digital-first aesthetic. Built to integrate seamlessly into our WordPress environment via custom shortcodes, the player hooks directly into our AzuraCast and Icecast stream endpoints while keeping things lightning-fast and responsive.

---

## 🎛️ Core Architecture & Functionality

* **Direct Stream Engine:** Pulls live audio feeds directly from our Icecast endpoints (`a4.asurahosting.com`), ensuring low latency and reliable playback.
* **Dynamic Metadata Polling:** Integrates metadata scripts (including Last.fm hook-ins where applicable) to fetch and display real-time track info right on the interface.
* **Lightweight DOM Control:** Vanilla JavaScript handles audio state (`play`, `pause`, volume ramping, and buffer states) without relying on heavy frontend frameworks.
* **WordPress Embedding:** Wrapped cleanly via custom PHP shortcodes (`[msfs_radio]`) in `functions.php`, allowing the player to sit natively inside the Classic Editor without causing layout shifts.

---

## 🖼️ UI Layouts & Visual Breakdowns

Since I'm a text-based collaborator and can't spit out actual PNG screenshots, I've mapped out the functional UI states below using clean interface mockups. This is exactly what the code renders in the browser.

### 1. The Default Idle / Muted State

*A minimalist dark-mode container featuring high-contrast monospace typography, a subtle border accent, and a standby play trigger.*

```text
+-------------------------------------------------------------+
>  YRB RADIO // LIVE STREAM                                   >
>  [  ▶  ]  CONNECT TO STREAM                     [ VOL: 80% ]  >
>  Status: Ready for broadcast...                             >
+-------------------------------------------------------------+

```

### 2. The Active Playing State

*When the stream fires up, the play icon shifts to pause, and the live metadata ticker kicks in to display the current track/show.*

```text
+-------------------------------------------------------------+
>  YRB RADIO // LIVE STREAM                          [ LIVE ] >
>  [  ⏸  ]  NOW PLAYING: [Artist - Track Title]        [ VOL: 80% ]  >
>  Status: Transmitting via AzuraCast / Icecast (128kbps)     >
+-------------------------------------------------------------+

```

### 3. The Code Skeleton (`index.html` structure)

*Underneath those visuals, the HTML structure is kept dead simple so styling can be completely controlled via custom CSS:*

```html
<div class="yrb-radio-player-container">
    <div class="player-header">
        <span class="station-title">YRB RADIO</span>
        <span class="live-badge">LIVE</span>
    </div>
    
    <div class="player-controls">
        <button id="playPauseBtn" class="retro-btn">▶</button>
        <div class="track-metadata">
            <span id="nowPlaying">Connecting to stream...</span>
        </div>
        <input type="range" id="volumeSlider" min="0" max="1" step="0.01" value="0.8">
    </div>
    
    <audio id="audioStream" preload="none">
        <source src="https://a4.asurahosting.com/public/yrbradio" type="audio/mpeg">
        Your browser does not support the audio element.
    </audio>
</div>

```

---

## 🛠️ Tech Stack at a Glance

* **Markup & Styling:** HTML5, CSS3 (utilizing dark-mode glassmorphism touches and clean monospace fonts).
* **Logic:** Vanilla ES6 JavaScript for media element control and async metadata fetching.
* **Backend / Delivery:** WordPress (`functions.php` PHP shortcodes), AzuraCast, and direct Icecast stream hosting.

Let me know if you want to tweak any of the JavaScript event listeners for the stream state or add some extra CSS flair to the player container!
