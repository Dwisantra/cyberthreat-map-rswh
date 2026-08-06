<template>
  <div class="map-container">
    <!-- Overlay Header -->
    <div class="header-overlay">
      <h2>CYBERTHREAT REAL-TIME MONITORING</h2>
      <p>LIVE SONICWALL THREAT - RSUWH</p>
    </div>

    <!-- Container Globe 3D -->
    <div ref="globeContainer" class="globe-view"></div>

    <!-- Overlay Log Feed -->
    <div class="log-overlay">
      <h3>ATTACK LOGS</h3>
      <ul>
        <li v-for="(log, idx) in attackLogs" :key="idx">
          <span class="time">[{{ log.time }}]</span>
          <span class="ip">{{ log.ip }}</span>
          <span class="location">({{ log.city }}, {{ log.country }})</span>
        </li>
      </ul>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import Globe from 'globe.gl';
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

const globeContainer = ref(null);
const attackLogs = ref([]);
let globeInstance;

window.Pusher = Pusher;

onMounted(() => {
  globeInstance = Globe()(globeContainer.value)
    .globeImageUrl('//unpkg.com/three-globe/example/img/earth-night.jpg')
    .bumpImageUrl('//unpkg.com/three-globe/example/img/earth-topology.png')
    .arcColor(() => ['#ff0055', '#e30000'])
    .arcDashLength(0.5)
    .arcDashGap(0.2)
    .arcDashAnimateTime(1200)
    .arcStroke(1.2);

  globeInstance.controls().autoRotate = true;
  globeInstance.controls().autoRotateSpeed = 0.5;

  window.Echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
    wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
    forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
    enabledTransports: ['ws', 'wss'],
  });

  window.Echo.channel('threat-channel')
    .listen('ThreatDetected', (e) => {
      const threat = e.threatData;
      const currentArcs = globeInstance.arcsData();
      globeInstance.arcsData([...currentArcs.slice(-25), {
        startLat: threat.srcLat,
        startLng: threat.srcLng,
        endLat: threat.dstLat,
        endLng: threat.dstLng,
      }]);

      attackLogs.value.unshift(threat);
      if (attackLogs.value.length > 12) attackLogs.value.pop();
    });
});
</script>