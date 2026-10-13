/**
 * HD Form Module Lazy-Load Configuration.
 *
 * Each entry maps a CSS selector to a dynamic loader function.
 *
 * @package HDForm
 */

export default {
	form: {
		selector: '[data-hd-form], [data-hde-form]',
		loader: () => import('./form'),
	},
	formTracking: {
		selector: '[data-hd-form][data-tracking], [data-hde-form][data-tracking]',
		loader: () => import('./form-tracking'),
	},
	formLogic: {
		selector: '[data-hd-form][data-logic], [data-hde-form][data-logic]',
		loader: () => import('./form-logic'),
	},
	formSteps: {
		selector: '[data-hd-form][data-multistep], [data-hde-form][data-multistep]',
		loader: () => import('./form-steps'),
	},
	formRepeater: {
		selector: '[data-hd-form] [data-repeater], [data-hde-form] [data-repeater]',
		loader: () => import('./form-repeater'),
	},
	formDropzone: {
		selector: '[data-hd-form] [data-dropzone], [data-hde-form] [data-dropzone]',
		loader: () => import('./form-dropzone'),
	},
	formDynamic: {
		selector:
			'[data-hd-form] [data-source], [data-hde-form] [data-source], [data-hd-form] [data-cascade-map], [data-hde-form] [data-cascade-map], [data-hd-form] [data-options], [data-hde-form] [data-options]',
		loader: () => import('./form-dynamic'),
	},
	formAutosave: {
		selector:
			'[data-hd-form][data-autosave], [data-hde-form][data-autosave], [data-hd-autosave], [data-hde-autosave], [data-multistep]:not([data-autosave="false"])',
		loader: () => import('./form-autosave'),
	},
	formCalc: {
		selector: '[data-hd-form] [data-calc], [data-hde-form] [data-calc], [data-hd-calc], [data-hde-calc]',
		loader: () => import('./form-calc'),
	},
	formMask: {
		selector: '[data-hd-form] [data-mask], [data-hde-form] [data-mask], [data-hd-mask], [data-hde-mask]',
		loader: () => import('./form-mask'),
	},
};
