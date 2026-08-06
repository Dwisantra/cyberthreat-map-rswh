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

const arcPalette = [
  ['#38bdf8', '#22d3ee'],
  ['#f97316', '#fb923c'],
  ['#a78bfa', '#8b5cf6'],
  ['#4ade80', '#16a34a'],
  ['#f43f5e', '#e11d48'],
  ['#facc15', '#f59e0b'],
];

const getArcColors = (threat) => {
  const seed = `${threat.country || 'unknown'}:${threat.city || 'unknown'}`.toLowerCase();
  const hash = [...seed].reduce((total, char) => total + char.charCodeAt(0), 0);
  return arcPalette[hash % arcPalette.length];
};

window.Pusher = Pusher;

onMounted(() => {
  globeInstance = Globe()(globeContainer.value)
    .globeImageUrl('//unpkg.com/three-globe/example/img/earth-night.jpg')
    .bumpImageUrl('//unpkg.com/three-globe/example/img/earth-topology.png')
    .arcColor((d) => d.color || ['#38bdf8', '#22d3ee'])
    .arcAltitude(0.16)
    .arcDashLength(0.24)
    .arcDashGap(0.08)
    .arcDashAnimateTime(1600)
    .arcStroke(0.35);

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
      globeInstance.arcsData([...currentArcs.slice(-20), {
        startLat: threat.srcLat,
        startLng: threat.srcLng,
        endLat: threat.dstLat,
        endLng: threat.dstLng,
        color: getArcColors(threat),
      }]);

      attackLogs.value.unshift(threat);
      if (attackLogs.value.length > 12) attackLogs.value.pop();
    });
});
</script>