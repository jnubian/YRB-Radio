# YRB-Radio
The 24/7 Funky Fresh OpenSource Cultural Radio Station
This is the breakdown for the **YRB Radio** web player (`[yrbmag.com/radio](https://yrbmag.com/radio)`). I wanted  a clean, minimalist terminal interface that worked well on desktop and mobile. This is how it all works.

---

## ⚡ What is YRB Radio?

At its core, YRB Radio is a custom-built, lightweight web streaming client designed to match a raw, digital-first aesthetic. The inspiration was from a fresh install of Omarchy on my Thinkpad t460, and the Cliamp player it comes with. Initially I built it to  integrate seamlessly into WordPress environment of the main YRBMAG.com website via custom shortcodes, but after some updates that proved clunky. I decided to have it live on it's own as a lean html file. The player hooks directly into our AzuraCast server and Icecast stream endpoints while keeping things fast and responsive.

---

## 🎛️ Core Architecture & Functionality

* **Direct Stream Engine:** Pulls live audio feeds directly from our Icecast endpoints , ensuring low latency and reliable playback.
* **Dynamic Metadata Polling:** Integrates metadata scripts (including Last.fm hook-ins where applicable) to fetch and display real-time track info right on the interface.
* **Lightweight DOM Control:** Vanilla JavaScript handles audio state (`play`, `pause`, volume ramping, and buffer states) without relying on heavy frontend frameworks.
* 





---

## 🖼️ UI Layouts & Visual Breakdowns
MOBILE

![alt text](https://yrbmag.com/images/covers/radio/1.png)

![alt text](https://yrbmag.com/images/covers/radio/3.png)

DESKTOP
![alt text](https://yrbmag.com/images/covers/radio/11.png)
![alt text](https://yrbmag.com/images/covers/radio/12.png)



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

![alt text](https://yrbmag.com/images/covers/radio/4.png)

![alt text](https://yrbmag.com/images/covers/radio/7.png)

```text
+-------------------------------------------------------------+
>  YRB RADIO // LIVE STREAM                          [ LIVE ] >
>  [  ⏸  ]  NOW PLAYING: [Artist - Track Title]        [ VOL: 80% ]  >
>  Status: Transmitting via AzuraCast / Icecast (128kbps)     >
+-------------------------------------------------------------+

```![alt text](https://yrbmag.com/images/covers/radio/2.png)

![alt text](https://yrbmag.com/images/covers/radio/5.png)

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
* **Backend / Delivery:**  AzuraCast, and direct Icecast stream hosting.

