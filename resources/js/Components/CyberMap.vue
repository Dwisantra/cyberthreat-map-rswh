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
    <div v-if="showLogs" class="log-overlay">
      <div class="overlay-header">
        <h3>ATTACK LOGS</h3>
        <button @click="togglePanel('logs')" class="toggle-btn">−</button>
      </div>
      <ul>
        <li v-for="(log, idx) in attackLogs" :key="idx">
          <span class="time">[{{ log.time }}]</span>
          <span class="ip">{{ log.ip }}</span>
          <span class="location">({{ log.city }}, {{ log.country }})</span>
        </li>
      </ul>
    </div>
    <div v-else class="log-overlay-collapsed">
      <button @click="togglePanel('logs')" class="toggle-btn-expand">ATTACK LOGS</button>
    </div>

    <!-- Top Countries Overlay -->
    <div v-if="showCountries" class="stats-overlay top-countries-overlay">
      <div class="overlay-header">
        <h3>TOP COUNTRIES</h3>
        <button @click="togglePanel('countries')" class="toggle-btn">−</button>
      </div>
      <ul>
        <li v-for="item in topCountries" :key="item.country">
          <span class="country">{{ item.country }}</span>
          <span class="count">{{ item.count }}</span>
        </li>
      </ul>
    </div>
    <div v-else class="stats-overlay-collapsed top-countries-overlay">
      <button @click="togglePanel('countries')" class="toggle-btn-expand">TOP COUNTRIES</button>
    </div>

    <!-- Top IPs Overlay -->
    <div v-if="showIps" class="stats-overlay top-ips-overlay">
      <div class="overlay-header">
        <h3>TOP IPs</h3>
        <button @click="togglePanel('ips')" class="toggle-btn">−</button>
      </div>
      <ul>
        <li v-for="item in topIps" :key="item.ip">
          <span class="ip-addr">{{ item.ip }}</span>
          <span class="count">{{ item.count }}</span>
        </li>
      </ul>
    </div>
    <div v-else class="stats-overlay-collapsed top-ips-overlay">
      <button @click="togglePanel('ips')" class="toggle-btn-expand">TOP IPs</button>
    </div>

    <!-- Attack Rate Overlay -->
    <div v-if="showRate" class="stats-overlay attack-rate-overlay">
      <div class="overlay-header">
        <h3>ATTACK RATE</h3>
        <button @click="togglePanel('rate')" class="toggle-btn">−</button>
      </div>
      <div class="rate-display">
        <span class="rate-value">{{ attackRate }}</span>
        <span class="rate-label">/min</span>
      </div>
    </div>
    <div v-else class="stats-overlay-collapsed attack-rate-overlay">
      <button @click="togglePanel('rate')" class="toggle-btn-expand">RATE</button>
    </div>

    <!-- Attack Counter Overlay -->
    <div v-if="showCounter" class="stats-overlay attack-counter-overlay">
      <div class="overlay-header">
        <h3>TOTAL ATTACKS</h3>
        <button @click="togglePanel('counter')" class="toggle-btn">−</button>
      </div>
      <div class="counter-display">
        <span class="counter-value">{{ Object.values(ipStats).reduce((a, b) => a + b, 0) }}</span>
      </div>
    </div>
    <div v-else class="stats-overlay-collapsed attack-counter-overlay">
      <button @click="togglePanel('counter')" class="toggle-btn-expand">TOTAL</button>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, computed } from 'vue';
import Globe from 'globe.gl';
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

const globeContainer = ref(null);
const attackLogs = ref([]);
const countryStats = ref({});
const ipStats = ref({});
const attackTimestamps = ref([]);
const showLogs = ref(true);
const showCountries = ref(true);
const showIps = ref(true);
const showRate = ref(true);
const showCounter = ref(true);
let globeInstance;

const togglePanel = (panel) => {
  if (panel === 'logs') showLogs.value = !showLogs.value;
  if (panel === 'countries') showCountries.value = !showCountries.value;
  if (panel === 'ips') showIps.value = !showIps.value;
  if (panel === 'rate') showRate.value = !showRate.value;
  if (panel === 'counter') showCounter.value = !showCounter.value;
};

const totalAttacks = computed(() => attackLogs.value.length + (attackTimestamps.value.length > 12 ? attackTimestamps.value.length - 12 : 0));

const topCountries = computed(() => {
  return Object.entries(countryStats.value)
    .sort((a, b) => b[1] - a[1])
    .slice(0, 5)
    .map(([country, count]) => ({ country, count }));
});

const topIps = computed(() => {
  return Object.entries(ipStats.value)
    .sort((a, b) => b[1] - a[1])
    .slice(0, 5)
    .map(([ip, count]) => ({ ip, count }));
});

const attackRate = computed(() => {
  const now = Date.now();
  const oneMinuteAgo = now - 60000;
  const recentAttacks = attackTimestamps.value.filter(t => t > oneMinuteAgo).length;
  return recentAttacks;
});

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
  const endLng = normalizeLongitude(threat.dstLng);

  const latDelta = Math.abs(endLat - startLat);
  const lngDelta = Math.abs(endLng - startLng);
  const distanceFactor = Math.min(1, (latDelta + lngDelta) / 180);
  const altitude = 0.03 + distanceFactor * 0.35;

  return {
    startLat: Number.isFinite(startLat) ? startLat : 0,
    startLng,
    endLat: Number.isFinite(endLat) ? endLat : 0,
    endLng,
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
    .arcDashAnimateTime(2200)
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
      
      countryStats.value[threat.country] = (countryStats.value[threat.country] || 0) + 1;
      ipStats.value[threat.ip] = (ipStats.value[threat.ip] || 0) + 1;
      attackTimestamps.value.push(Date.now());
      
      if (attackTimestamps.value.length > 1000) {
        attackTimestamps.value.shift();
      }
    });
});
</script>