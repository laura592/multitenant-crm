import './bootstrap';

// Uniforma il feedback di tutti i pulsanti "Salva"/"Crea" delle pagine
// risorsa Filament (Clienti, Preventivi, Rapportini, ecc.): di suo Filament
// mostra solo una piccola icona che ruota al posto dell'icona del pulsante,
// facile da non notare su tablet/cellulare. Il markup del bottone (vendor/
// filament/support/.../button/index.blade.php) supporta gia' uno scambio di
// etichetta con "processingMessage" + un effetto "cursor-wait", ma lo attiva
// solo per l'upload file (form-processing-started/finished dispatchati da
// file-upload.js) — qui li dispatchiamo anche per il salvataggio vero e
// proprio, intercettando il "commit" Livewire delle chiamate a save()/create().
function setupSaveButtonProcessingIndicator() {
	if (!window.Livewire?.hook) {
		return;
	}

	const savingMethods = new Set(['save', 'create']);

	window.Livewire.hook('commit', ({ component, commit, succeed, fail }) => {
		const calls = commit?.calls ?? [];
		if (!calls.some((call) => savingMethods.has(call.method))) {
			return;
		}

		const forms = component?.el?.querySelectorAll('form.fi-form') ?? [];
		if (!forms.length) {
			return;
		}

		const dispatchOnForms = (eventName, detail) => {
			forms.forEach((form) => form.dispatchEvent(new CustomEvent(eventName, { detail })));
		};

		dispatchOnForms('form-processing-started', { message: 'Salvataggio…' });

		const finish = () => dispatchOnForms('form-processing-finished');
		succeed(finish);
		fail(finish);
	});
}

document.addEventListener('livewire:init', setupSaveButtonProcessingIndicator);

// Di suo, quando una richiesta Livewire (salvataggio, cambio tab, ecc.) va in
// 419 perche' la sessione e' scaduta, Livewire mostra un confirm() nativo del
// browser in inglese e poi comunque un modale con l'HTML grezzo della
// risposta d'errore (vendor/livewire/livewire/dist/livewire.js, handlePageExpiry
// + showFailureModal, chiamati entrambi senza early return). Intercettiamo il
// fallimento prima che Livewire lo gestisca a modo suo e mandiamo l'utente
// sulla pagina "sessione scaduta" brandizzata (resources/views/errors/419.blade.php).
document.addEventListener('livewire:init', () => {
	Livewire.hook('request', ({ fail }) => {
		fail(({ status, preventDefault }) => {
			if (status === 419) {
				preventDefault();
				// Passiamo l'URL corrente cosi' la pagina di sessione scaduta puo'
				// salvarlo come "url.intended": dopo il login Filament ci riporta li'
				// invece che sulla dashboard di default (vedi errors/419.blade.php).
				const redirect = encodeURIComponent(window.location.href);
				window.location.href = `/sessione-scaduta?redirect=${redirect}`;
			}
		});
	});
});

// Di suo, un errore server non gestito (es. eccezione durante un'azione
// dentro un form Filament) fa mostrare a Livewire l'HTML grezzo della
// risposta d'errore in un overlay a pagina intera — pessima UX (sembra
// un crash totale dell'app) e in piu' espone lo stack trace se APP_DEBUG
// e' attivo. L'errore resta comunque loggato lato server (report() nel
// normale flusso Laravel, prima che la risposta arrivi qui); qui si
// sostituisce solo la presentazione con un toast, stesso stile delle
// notifiche Filament native (window.FilamentNotification, esposta dal
// pacchetto filament/notifications). Non tocca 419 (gestito sopra) ne'
// gli altri stati (401/403/404/422): quelli hanno gia' un trattamento
// specifico o arrivano come risposta "riuscita" col proprio HTTP status.
document.addEventListener('livewire:init', () => {
	Livewire.hook('request', ({ fail }) => {
		fail(({ status, preventDefault }) => {
			if (status < 500) {
				return;
			}

			preventDefault();

			new window.FilamentNotification()
				.title('Si è verificato un errore')
				.body('L\'operazione non è andata a buon fine. Riprova; se il problema persiste contatta l\'assistenza.')
				.danger()
				.send();
		});
	});
});

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
