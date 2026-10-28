/**
 * HD Form Tracking — multi-provider event dispatcher (GA4, GTM, Meta Pixel, TikTok Pixel).
 *
 * Listens for `hd:form:success` or `hde:form:success` custom events from form.ts
 * and routes to registered tracking providers.
 *
 * @package HDForm
 */

import { on, off } from '../shared/helpers';

const DEFAULT_GTM_EVENT = 'form_submit';
const DEFAULT_GA4_EVENT = 'generate_lead';
const DEFAULT_FBQ_EVENT = 'Lead';
const DEFAULT_TTQ_EVENT = 'SubmitForm';

type ProviderConfig = string | { event?: string; [key: string]: unknown };

interface TrackingDetail {
	formType: string;
	formId: string;
	pageUrl: string;
	tracking: Record<string, ProviderConfig>;
}

type ProviderCallback = (config: ProviderConfig, detail: TrackingDetail) => void;

const providers = new Map<string, ProviderCallback>();

function registerProvider(name: string, callback: ProviderCallback): void {
	providers.set(name, callback);
}

function parseProviderConfig(config: ProviderConfig, defaultEvent: string): { eventName: string; extra: Record<string, unknown> } {
	if (typeof config === 'string') {
		return { eventName: config || defaultEvent, extra: {} };
	}
	const { event, ...extra } = config;
	return { eventName: (event as string) || defaultEvent, extra };
}

export { registerProvider };

// Google Tag Manager (dataLayer)
registerProvider('gtm', (config, detail) => {
	if (!(window as any).dataLayer) return;
	const { eventName, extra } = parseProviderConfig(config, DEFAULT_GTM_EVENT);
	(window as any).dataLayer.push({
		event: eventName,
		form_type: detail.formType,
		form_id: detail.formId,
		page_url: detail.pageUrl,
		...extra,
	});
});

// GA4 Direct (gtag)
registerProvider('ga4', (config, detail) => {
	if (typeof (window as any).gtag !== 'function') return;
	const { eventName, extra } = parseProviderConfig(config, DEFAULT_GA4_EVENT);
	(window as any).gtag('event', eventName, {
		form_type: detail.formType,
		form_id: detail.formId,
		page_url: detail.pageUrl,
		...extra,
	});
});

// Facebook / Meta Pixel (fbq)
registerProvider('fbq', (config, detail) => {
	if (typeof (window as any).fbq !== 'function') return;
	const { eventName, extra } = parseProviderConfig(config, DEFAULT_FBQ_EVENT);
	(window as any).fbq('track', eventName, {
		content_name: detail.formType,
		content_category: detail.formId,
		...extra,
	});
});

// TikTok Pixel (ttq)
registerProvider('ttq', (config, detail) => {
	if (!(window as any).ttq?.track) return;
	const { eventName, extra } = parseProviderConfig(config, DEFAULT_TTQ_EVENT);
	(window as any).ttq.track(eventName, {
		content_type: detail.formType,
		content_id: detail.formId,
		...extra,
	});
});

function handleFormSuccess(e: Event): void {
	const detail = (e as CustomEvent<TrackingDetail>).detail;
	if (!detail?.tracking) return;

	providers.forEach((callback, name) => {
		const config = detail.tracking[name];
		if (config !== undefined) {
			try {
				callback(config, detail);
			} catch (err) {
				console.warn(`[HDForm] Tracking provider "${name}" threw:`, err);
			}
		}
	});
}

export default {
	initAll(_root: Document | Element = document): void {
		off(document, 'hd:form:success', handleFormSuccess);
		off(document, 'hde:form:success', handleFormSuccess);
		on(document, 'hd:form:success', handleFormSuccess);
		on(document, 'hde:form:success', handleFormSuccess);
	},

	destroyAll(_root: Document | Element = document): void {
		off(document, 'hd:form:success', handleFormSuccess);
		off(document, 'hde:form:success', handleFormSuccess);
	},
};
