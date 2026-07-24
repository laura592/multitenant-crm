import './bootstrap';

const nearbyMapInstances = new WeakMap();

const ensureLeafletLoaded = (() => {
	let loadingPromise = null;

	return () => {
		if (window.L) {
			return Promise.resolve(window.L);
		}

		if (loadingPromise) {
			return loadingPromise;
		}

		loadingPromise = new Promise((resolve, reject) => {
			const timeoutMs = 7000;
			const timeoutId = window.setTimeout(() => {
				reject(new Error('Leaflet load timeout'));
			}, timeoutMs);

			if (!document.querySelector('link[data-nearby-map-leaflet-css]')) {
				const css = document.createElement('link');
				css.rel = 'stylesheet';
				css.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
				css.setAttribute('data-nearby-map-leaflet-css', '1');
				document.head.appendChild(css);
			}

			const onLoaded = () => {
				window.clearTimeout(timeoutId);
				resolve(window.L);
			};

			const onError = (error) => {
				window.clearTimeout(timeoutId);
				reject(error instanceof Error ? error : new Error('Leaflet load failed'));
			};

			const existingScript = document.querySelector('script[data-nearby-map-leaflet-js]');
			if (existingScript) {
				if (window.L) {
					onLoaded();
				} else {
					existingScript.addEventListener('load', onLoaded, { once: true });
					existingScript.addEventListener('error', onError, { once: true });
				}

				return;
			}

			const script = document.createElement('script');
			script.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
			script.async = true;
			script.setAttribute('data-nearby-map-leaflet-js', '1');
			script.addEventListener('load', onLoaded, { once: true });
			script.addEventListener('error', onError, { once: true });
			document.head.appendChild(script);
		});

		return loadingPromise;
	};
})();

const escapeHtml = (value) => String(value ?? '')
	.replaceAll('&', '&amp;')
	.replaceAll('<', '&lt;')
	.replaceAll('>', '&gt;')
	.replaceAll('"', '&quot;')
	.replaceAll("'", '&#39;');

async function renderNearbyMap(mapElement) {
	if (!(mapElement instanceof HTMLElement)) {
		return;
	}

	const userLat = Number(mapElement.dataset.userLat);
	const userLng = Number(mapElement.dataset.userLng);
	const markersId = mapElement.dataset.markersId;

	if (Number.isNaN(userLat) || Number.isNaN(userLng) || !markersId) {
		return;
	}

	const markersScript = document.getElementById(markersId);
	const markersJson = markersScript?.textContent ?? '[]';
	const signature = `${userLat}|${userLng}|${markersJson}`;

	if (mapElement.dataset.nearbyMapSignature === signature) {
		return;
	}

	const wrapper = mapElement.parentElement;
	const statusElement = wrapper?.querySelector('[data-nearby-map-status]');
	const fallbackElement = wrapper?.querySelector('[data-nearby-map-fallback]');

	if (statusElement) {
		statusElement.textContent = 'Caricamento mappa in corso...';
		statusElement.classList.remove('text-amber-700', 'dark:text-amber-200');
		statusElement.classList.add('text-gray-500', 'dark:text-gray-400');
	}

	try {
		const markers = JSON.parse(markersJson);
		const L = await ensureLeafletLoaded();

		const previousMap = nearbyMapInstances.get(mapElement);
		if (previousMap) {
			previousMap.remove();
		}

		const map = L.map(mapElement, {
			zoomControl: true,
			scrollWheelZoom: true,
		});

		L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
			maxZoom: 19,
			attribution: '&copy; OpenStreetMap contributors',
		}).addTo(map);

		const bounds = L.latLngBounds([[userLat, userLng]]);

		const userMarker = L.circleMarker([userLat, userLng], {
			radius: 8,
			color: '#2563eb',
			fillColor: '#2563eb',
			fillOpacity: 0.9,
			weight: 2,
		}).addTo(map);
		userMarker.bindPopup('Posizione corrente');

		for (const marker of markers || []) {
			const lat = Number(marker.lat);
			const lng = Number(marker.lng);

			if (Number.isNaN(lat) || Number.isNaN(lng)) {
				continue;
			}

			const customerMarker = L.marker([lat, lng]).addTo(map);
			bounds.extend([lat, lng]);

			const popup = `
				<div style="min-width: 180px; font-size: 13px; line-height: 1.35;">
					<strong>${escapeHtml(marker.name ?? 'Cliente')}</strong><br>
					${escapeHtml(marker.street ?? '')}${marker.city ? ', ' + escapeHtml(marker.city) : ''}<br>
					<span style="color:#6b7280;">${escapeHtml(marker.distance ?? '-')} km · ${escapeHtml(marker.source ?? '')}</span><br>
					<a href="${escapeHtml(marker.mapsUrl)}" target="_blank" rel="noopener" style="color:#2563eb;">Apri in Maps</a>
				</div>
			`;

			customerMarker.bindPopup(popup);
		}

		if ((markers || []).length > 0) {
			map.fitBounds(bounds.pad(0.2));
		} else {
			map.setView([userLat, userLng], 13);
		}

		nearbyMapInstances.set(mapElement, map);
		mapElement.dataset.nearbyMapSignature = signature;

		if (statusElement) {
			statusElement.textContent = '';
		}

		if (fallbackElement) {
			fallbackElement.classList.add('hidden');
		}
	} catch (error) {
		console.error('Nearby customers map render failed', error);

		if (statusElement) {
			statusElement.textContent = 'Mappa interattiva non disponibile. Uso fallback statico.';
			statusElement.classList.remove('text-gray-500', 'dark:text-gray-400');
			statusElement.classList.add('text-amber-700', 'dark:text-amber-200');
		}

		if (fallbackElement) {
			fallbackElement.classList.remove('hidden');
		}
	}
}

function renderAllNearbyMaps() {
	document.querySelectorAll('[data-nearby-map="1"]').forEach((element) => {
		renderNearbyMap(element);
	});
}

let renderScheduled = false;
function scheduleNearbyMapRender() {
	if (renderScheduled) {
		return;
	}

	renderScheduled = true;

	requestAnimationFrame(() => {
		renderScheduled = false;
		renderAllNearbyMaps();
	});
}

function closeSidebarOnMobile() {
	if (!window.matchMedia('(max-width: 1023.98px)').matches) {
		return;
	}

	const sidebarStore = window.Alpine?.store?.('sidebar');

	if (sidebarStore && sidebarStore.isOpen) {
		sidebarStore.isOpen = false;
	}
}

document.addEventListener('DOMContentLoaded', scheduleNearbyMapRender);
document.addEventListener('livewire:navigated', scheduleNearbyMapRender);
window.addEventListener('nearby-map:render', scheduleNearbyMapRender);

document.addEventListener('DOMContentLoaded', closeSidebarOnMobile);
document.addEventListener('livewire:navigated', closeSidebarOnMobile);
window.addEventListener('resize', closeSidebarOnMobile);

if (document.body) {
	const observer = new MutationObserver(() => scheduleNearbyMapRender());
	observer.observe(document.body, {
		childList: true,
		subtree: true,
	});
}
