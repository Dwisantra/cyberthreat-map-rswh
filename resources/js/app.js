// import './bootstrap';
import { createApp, h } from 'vue';
import CyberMap from './Components/CyberMap.vue';

const app = createApp({
    render: () => h(CyberMap)
});

app.mount('#app');