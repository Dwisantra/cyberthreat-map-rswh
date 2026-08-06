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

const normalizeLongitude = (value) => {
  const numeric = Number(value);
  if (!Number.isFinite(numeric)) return 0;

  let normalized = numeric;
  while (normalized < -180) normalized += 360;
  while (normalized > 180) normalized -= 360;
  return normalized;
};

const getShortestLongitudePair = (startLng, endLng) => {
  let delta = endLng - startLng;
  while (delta > 180) delta -= 360;
  while (delta < -180) delta += 360;
  return [startLng, startLng + delta];
};

const getArcColors = (threat) => {
  const seed = `${threat.country || 'unknown'}:${threat.city || 'unknown'}`.toLowerCase();
  const hash = [...seed].reduce((total, char) => total + char.charCodeAt(0), 0);
  return arcPalette[hash % arcPalette.length];
};

const buildArcData = (threat) => {
  const startLat = Number(threat.srcLat);
  const startLng = normalizeLongitude(threat.srcLng);
  const endLat = Number(threat.dstLat);
  const rawEndLng = normalizeLongitude(threat.dstLng);
  const [adjustedStartLng, adjustedEndLng] = getShortestLongitudePair(startLng, rawEndLng);

  const latDelta = Math.abs(endLat - startLat);
  const lngDelta = Math.abs(adjustedEndLng - adjustedStartLng);
  const distanceFactor = Math.min(1, (latDelta + lngDelta) / 180);
  const altitude = 0.04 + distanceFactor * 0.1;

  return {
    startLat: Number.isFinite(startLat) ? startLat : 0,
    startLng: adjustedStartLng,
    endLat: Number.isFinite(endLat) ? endLat : 0,
    endLng: adjustedEndLng,
    color: getArcColors(threat),
    altitude,
  };
};

window.Pusher = Pusher;

onMounted(() => {
  globeInstance = Globe()(globeContainer.value)
    .globeImageUrl('//unpkg.com/three-globe/example/img/earth-night.jpg')
    .bumpImageUrl('//unpkg.com/three-globe/example/img/earth-topology.png')
    .arcColor((d) => d.color || ['#38bdf8', '#22d3ee'])
    .arcAltitude((d) => d.altitude || 0.08)
    .arcDashLength(0.16)
    .arcDashGap(0.04)
    .arcDashAnimateTime(1400)
    .arcStroke(0.55);

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
      globeInstance.arcsData([...currentArcs.slice(-20), buildArcData(threat)]);

      attackLogs.value.unshift(threat);
      if (attackLogs.value.length > 12) attackLogs.value.pop();
    });
});
</script>