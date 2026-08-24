import L from 'leaflet';
import 'leaflet.markercluster';

const importButton = document.querySelector('#import-button');
const archiveFile = document.querySelector('#archive-file');
const uploadPanel = document.querySelector('#upload-panel');
const uploadTitle = document.querySelector('#upload-title');
const uploadDetail = document.querySelector('#upload-detail');
const uploadProgress = document.querySelector('#upload-progress');
const uploadCancel = document.querySelector('#upload-cancel');

let uploadController;

importButton?.addEventListener('click', () => archiveFile.click());
uploadCancel?.addEventListener('click', () => uploadController?.abort());

archiveFile?.addEventListener('change', async () => {
    const file = archiveFile.files[0];
    if (!file) return;
    if (!/\.(zip|tar\.gz|tgz)$/i.test(file.name)) {
        showUploadError('Please choose a ZIP, TAR.GZ, or TGZ archive.');
        return;
    }

    const chunkSize = 4 * 1024 * 1024;
    const total = Math.ceil(file.size / chunkSize);
    const uploadId = crypto.randomUUID();
    uploadController = new AbortController();
    uploadPanel.hidden = false;
    uploadPanel.className = 'upload-panel';
    uploadCancel.hidden = false;
    uploadProgress.value = 0;
    uploadTitle.textContent = `Uploading ${file.name}`;

    try {
        for (let index = 0; index < total; index++) {
            const start = index * chunkSize;
            const chunk = file.slice(start, Math.min(start + chunkSize, file.size));
            const parameters = new URLSearchParams({ upload_id: uploadId, filename: file.name, index, total });
            let response;

            for (let attempt = 1; attempt <= 3; attempt++) {
                try {
                    response = await fetch(`/imports/chunk?${parameters}`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/octet-stream',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        },
                        body: chunk,
                        signal: uploadController.signal,
                    });
                    if (response.ok || response.status < 500) break;
                } catch (error) {
                    if (error.name === 'AbortError' || attempt === 3) throw error;
                }
            }

            const result = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(result.message || `Upload failed (${response.status}).`);
            uploadProgress.value = Math.round(((index + 1) / total) * 100);
            uploadDetail.textContent = index + 1 === total
                ? 'Upload complete — importing and indexing…'
                : `${formatBytes(Math.min((index + 1) * chunkSize, file.size))} of ${formatBytes(file.size)}`;

            if (result.complete) {
                uploadPanel.classList.add('success');
                uploadTitle.textContent = 'Archive imported';
                uploadDetail.textContent = result.message;
                uploadCancel.hidden = true;
                setTimeout(() => window.location.reload(), 900);
            }
        }
    } catch (error) {
        showUploadError(error.name === 'AbortError' ? 'Upload cancelled. Select the archive to try again.' : error.message);
    } finally {
        archiveFile.value = '';
    }
});

function showUploadError(message) {
    uploadPanel.hidden = false;
    uploadPanel.className = 'upload-panel error';
    uploadTitle.textContent = 'Import did not finish';
    uploadDetail.textContent = message;
    uploadCancel.hidden = true;
}

function formatBytes(bytes) {
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`;
    if (bytes < 1024 * 1024 * 1024) return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
    return `${(bytes / 1024 / 1024 / 1024).toFixed(2)} GB`;
}

const lightbox = document.createElement('dialog');
lightbox.className = 'lightbox';
lightbox.setAttribute('aria-label', 'Media viewer');
lightbox.innerHTML = '<button class="lightbox-close" type="button" aria-label="Close media viewer">×</button><div class="lightbox-content"></div>';
document.body.append(lightbox);

const lightboxContent = lightbox.querySelector('.lightbox-content');
lightbox.querySelector('.lightbox-close').addEventListener('click', () => lightbox.close());
lightbox.addEventListener('click', (event) => {
    if (event.target === lightbox) lightbox.close();
});
lightbox.addEventListener('close', () => {
    lightboxContent.querySelector('video')?.pause();
    lightboxContent.replaceChildren();
});

document.addEventListener('click', (event) => {
    const trigger = event.target.closest('[data-lightbox]');
    if (!trigger) return;
    event.preventDefault();
    const source = trigger.dataset.mediaSrc || trigger.currentSrc || trigger.src;
    const media = document.createElement(trigger.dataset.mediaType === 'video' ? 'video' : 'img');
    media.src = source;
    if (media instanceof HTMLVideoElement) {
        media.controls = true;
        media.autoplay = true;
    } else {
        media.alt = trigger.alt || 'Full-size archived image';
    }
    lightboxContent.replaceChildren(media);
    lightbox.showModal();
}, true);

const mapElement = document.querySelector('#archive-map');
if (mapElement) {
    const map = L.map(mapElement, { preferCanvas: true }).setView([39, -96], 4);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
    }).addTo(map);
    const points = JSON.parse(document.querySelector('#map-data')?.textContent || '[]');
    const clusters = L.markerClusterGroup({ chunkedLoading: true, maxClusterRadius: 48, showCoverageOnHover: false });
    const bounds = [];
    points.forEach((point) => {
        const service = point.service === 'twitter' ? 'x' : point.service;
        const icon = point.photo
            ? L.divIcon({ className: 'archive-map-icon archive-map-photo-icon', html: `<img src="${escapeHtml(point.photo)}" alt="">`, iconSize: [42, 42], iconAnchor: [21, 21] })
            : L.divIcon({ className: `archive-map-icon service-${service}`, html: `<span>${service === 'x' ? '𝕏' : escapeHtml(service.slice(0, 1).toUpperCase())}</span>`, iconSize: [30, 30], iconAnchor: [15, 15] });
        const marker = L.marker([point.latitude, point.longitude], { icon });
        const photo = point.photo ? `<img class="archive-map-popup-photo" src="${escapeHtml(point.photo)}" alt="${escapeHtml(point.photo_alt || 'Archived photo')}" data-lightbox data-media-type="image" data-media-src="${escapeHtml(point.photo)}">` : '';
        marker.bindPopup(`<div class="archive-map-popup">${photo}<strong>${escapeHtml(point.place)}</strong><small>${escapeHtml(point.account || '')} · ${escapeHtml(point.date || '')}</small><p>${escapeHtml(point.excerpt || '')}</p><a href="${escapeHtml(point.url)}">View post →</a></div>`, { maxWidth: 300 });
        clusters.addLayer(marker);
        bounds.push([point.latitude, point.longitude]);
    });
    map.addLayer(clusters);
    if (bounds.length) map.fitBounds(bounds, { padding: [28, 28], maxZoom: 13 });
}

document.querySelectorAll('[data-load-mini-map]').forEach((button) => {
    button.addEventListener('click', () => {
        const element = button.closest('[data-mini-map]');
        if (!element || element.dataset.mapReady) return;
        const latitude = Number(element.dataset.latitude);
        const longitude = Number(element.dataset.longitude);
        if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) return;

        element.dataset.mapReady = 'true';
        element.replaceChildren();
        const miniMap = L.map(element, {
            attributionControl: true,
            dragging: false,
            doubleClickZoom: false,
            boxZoom: false,
            keyboard: false,
            scrollWheelZoom: false,
            touchZoom: false,
            zoomControl: false,
        }).setView([latitude, longitude], 12);
        miniMap.attributionControl.setPrefix(false);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap',
        }).addTo(miniMap);
        L.circleMarker([latitude, longitude], {
            radius: 7,
            color: '#fff',
            weight: 3,
            fillColor: '#375d4a',
            fillOpacity: 1,
        }).addTo(miniMap);
    });
});

function escapeHtml(value) {
    return String(value).replace(/[&<>'"]/g, (character) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' })[character]);
}

const shareDialog = document.querySelector('[data-share-card-dialog]');
const shareGenerator = document.querySelector('#share-card-generator');

if (shareDialog && shareGenerator) {
    const canvas = shareGenerator.querySelector('canvas');
    const context = canvas.getContext('2d');
    const status = shareGenerator.querySelector('[data-share-status]');
    const data = JSON.parse(shareDialog.querySelector('[data-share-card-data]').textContent);
    let archivedImage = null;
    let renderVersion = 0;

    if (data.image) {
        archivedImage = new Image();
        archivedImage.decoding = 'async';
        archivedImage.src = data.image;
        archivedImage.addEventListener('load', () => renderShareCard());
        archivedImage.addEventListener('error', () => {
            archivedImage = null;
            shareGenerator.querySelector('[name="share_media"]')?.closest('label')?.remove();
            renderShareCard();
        });
    }

    document.querySelector('[data-open-share-card]')?.addEventListener('click', () => {
        shareDialog.showModal();
        renderShareCard();
    });
    shareDialog.querySelector('[data-close-share-card]').addEventListener('click', () => shareDialog.close());
    shareDialog.addEventListener('click', (event) => {
        if (event.target === shareDialog) shareDialog.close();
    });
    shareGenerator.querySelectorAll('input').forEach((input) => input.addEventListener('change', renderShareCard));

    shareGenerator.querySelector('[data-download-share-card]').addEventListener('click', async () => {
        await renderShareCard();
        const link = document.createElement('a');
        link.download = `archive-memory-${data.year || 'post'}.png`;
        link.href = canvas.toDataURL('image/png');
        link.click();
        setShareStatus('PNG downloaded. It contains no Afterfeed link or source metadata.');
    });

    shareGenerator.querySelector('[data-copy-share-card]').addEventListener('click', async () => {
        try {
            await renderShareCard();
            const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/png'));
            if (!blob || !navigator.clipboard || typeof ClipboardItem === 'undefined') throw new Error('unsupported');
            await navigator.clipboard.write([new ClipboardItem({ 'image/png': blob })]);
            setShareStatus('Image copied to your clipboard.');
        } catch {
            setShareStatus('Image copying is not available in this browser. Download the PNG instead.', true);
        }
    });

    async function renderShareCard() {
        const version = ++renderVersion;
        await document.fonts?.ready;
        if (version !== renderVersion) return;

        const shape = shareGenerator.querySelector('[name="share_shape"]:checked').value;
        const dimensions = { square: [1080, 1080], portrait: [1080, 1350], landscape: [1200, 675] }[shape];
        canvas.width = dimensions[0];
        canvas.height = dimensions[1];

        const showIdentity = shareGenerator.querySelector('[name="share_identity"]').checked;
        const showPlatform = shareGenerator.querySelector('[name="share_platform"]').checked;
        const showFullDate = shareGenerator.querySelector('[name="share_date"]').checked;
        const showFooter = shareGenerator.querySelector('[name="share_footer"]').checked;
        const showMedia = Boolean(archivedImage?.complete && archivedImage.naturalWidth && shareGenerator.querySelector('[name="share_media"]')?.checked);
        const [width, height] = dimensions;
        const padding = shape === 'landscape' ? 64 : 76;
        const accent = platformColor(data.platform);

        context.fillStyle = '#f5f1e8';
        context.fillRect(0, 0, width, height);
        context.fillStyle = accent;
        context.fillRect(0, 0, 18, height);
        context.fillStyle = `${accent}18`;
        context.beginPath();
        context.arc(width - 20, 10, shape === 'portrait' ? 290 : 230, 0, Math.PI * 2);
        context.fill();

        let textX = padding;
        let textWidth = width - padding * 2;
        let textTop = padding;
        let textBottom = height - padding - 74;

        if (showMedia && shape === 'landscape') {
            const mediaX = Math.round(width * .57);
            drawCoverImage(context, archivedImage, mediaX, 0, width - mediaX, height);
            context.fillStyle = '#f5f1e8';
            context.fillRect(0, 0, mediaX, height);
            context.fillStyle = accent;
            context.fillRect(0, 0, 18, height);
            textWidth = mediaX - padding * 1.65;
        } else if (showMedia) {
            const mediaHeight = shape === 'portrait' ? 530 : 390;
            drawCoverImage(context, archivedImage, 0, 0, width, mediaHeight);
            const fade = context.createLinearGradient(0, mediaHeight - 100, 0, mediaHeight + 35);
            fade.addColorStop(0, '#f5f1e800');
            fade.addColorStop(.78, '#f5f1e8');
            context.fillStyle = fade;
            context.fillRect(0, mediaHeight - 100, width, 140);
            textTop = mediaHeight - 20;
        }

        if (showPlatform) {
            const label = platformLabel(data.platform).toUpperCase();
            context.font = "700 22px 'DM Sans', sans-serif";
            const labelWidth = context.measureText(label).width + 34;
            roundRect(context, textX, textTop, labelWidth, 42, 21);
            context.fillStyle = accent;
            context.fill();
            context.fillStyle = '#fff';
            context.fillText(label, textX + 17, textTop + 28);
            textTop += 72;
        }

        if (showIdentity) {
            context.fillStyle = '#20231f';
            context.font = "700 29px 'DM Sans', sans-serif";
            context.fillText(data.name || data.handle || 'Archived post', textX, textTop + 28, textWidth);
            if (data.handle && data.handle !== data.name) {
                context.fillStyle = '#70756d';
                context.font = "500 22px 'DM Sans', sans-serif";
                context.fillText(data.handle, textX, textTop + 59, textWidth);
                textTop += 81;
            } else {
                textTop += 52;
            }
        }

        const dateLabel = showFullDate ? data.date : data.year;
        if (dateLabel) {
            context.fillStyle = '#747970';
            context.font = "600 20px 'DM Sans', sans-serif";
            context.fillText(dateLabel, textX, textTop + 20);
            textTop += 50;
        }

        context.fillStyle = '#20231f';
        const quoteSize = fitWrappedText(context, `“${data.body || 'An archived memory'}”`, textWidth, textBottom - textTop, shape === 'landscape' ? 44 : 51, 31);
        context.font = `500 ${quoteSize}px Newsreader, Georgia, serif`;
        drawWrappedText(context, `“${data.body || 'An archived memory'}”`, textX, textTop, textWidth, quoteSize * 1.18, textBottom);

        context.strokeStyle = '#d7d2c8';
        context.lineWidth = 2;
        context.beginPath();
        context.moveTo(textX, height - padding - 42);
        context.lineTo(shape === 'landscape' && showMedia ? textX + textWidth : width - padding, height - padding - 42);
        context.stroke();
        context.fillStyle = '#747970';
        context.font = "600 20px 'DM Sans', sans-serif";
        context.fillText(showFooter ? 'FROM MY ARCHIVE' : 'ARCHIVED MEMORY', textX, height - padding);
    }

    function setShareStatus(message, error = false) {
        status.textContent = message;
        status.classList.toggle('error', error);
    }
}

function platformLabel(platform) {
    return ({ twitter: 'Twitter / X', x: 'Twitter / X', facebook: 'Facebook', reddit: 'Reddit', instagram: 'Instagram', mastodon: 'Mastodon', livejournal: 'LiveJournal', bluesky: 'Bluesky', google_plus: 'Google+' })[platform] || platform || 'Social archive';
}

function platformColor(platform) {
    return ({ twitter: '#242424', x: '#242424', facebook: '#4267b2', reddit: '#d95d36', instagram: '#a93f77', mastodon: '#6254c7', livejournal: '#4f8063', bluesky: '#287fd1', google_plus: '#b5483b' })[platform] || '#375d4a';
}

function roundRect(context, x, y, width, height, radius) {
    context.beginPath();
    context.roundRect(x, y, width, height, radius);
}

function drawCoverImage(context, image, x, y, width, height) {
    const scale = Math.max(width / image.naturalWidth, height / image.naturalHeight);
    const sourceWidth = width / scale;
    const sourceHeight = height / scale;
    const sourceX = (image.naturalWidth - sourceWidth) / 2;
    const sourceY = (image.naturalHeight - sourceHeight) / 2;
    context.drawImage(image, sourceX, sourceY, sourceWidth, sourceHeight, x, y, width, height);
}

function wrappedLines(context, text, maxWidth) {
    const paragraphs = String(text).replace(/\s+/g, ' ').trim().split('\n');
    const lines = [];
    paragraphs.forEach((paragraph) => {
        const words = paragraph.split(' ');
        let line = '';
        words.forEach((word) => {
            const candidate = line ? `${line} ${word}` : word;
            if (line && context.measureText(candidate).width > maxWidth) {
                lines.push(line);
                line = word;
            } else {
                line = candidate;
            }
        });
        if (line) lines.push(line);
    });
    return lines;
}

function fitWrappedText(context, text, maxWidth, maxHeight, preferredSize, minimumSize) {
    for (let size = preferredSize; size >= minimumSize; size -= 2) {
        context.font = `500 ${size}px Newsreader, Georgia, serif`;
        if (wrappedLines(context, text, maxWidth).length * size * 1.18 <= maxHeight) return size;
    }
    return minimumSize;
}

function drawWrappedText(context, text, x, y, maxWidth, lineHeight, bottom) {
    const lines = wrappedLines(context, text, maxWidth);
    const maxLines = Math.max(1, Math.floor((bottom - y) / lineHeight));
    lines.slice(0, maxLines).forEach((line, index) => {
        let output = line;
        if (index === maxLines - 1 && lines.length > maxLines) {
            while (context.measureText(`${output}…`).width > maxWidth && output.length) output = output.slice(0, -1);
            output = `${output.trimEnd()}…`;
        }
        context.fillText(output, x, y + lineHeight * (index + 1));
    });
}
