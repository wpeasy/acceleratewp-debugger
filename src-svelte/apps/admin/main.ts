import { mount } from 'svelte';
import App from './App.svelte';

const target = document.getElementById('awpd-admin-app');
if (target) {
    mount(App, { target });
}
