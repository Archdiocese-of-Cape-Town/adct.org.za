/*
 * Candidate detail screen. Progressive disclosure only: every control is
 * visible and usable without JavaScript, and this just removes the choices that
 * cannot apply. It never submits, validates or stores anything.
 */
(function () {
	'use strict';

	/**
	 * Show or hide a control and its form-table row.
	 *
	 * Disabling rather than hiding alone matters: a disabled control is not
	 * posted, so a value the reviewer cannot see cannot reach the validator.
	 *
	 * @param {string}  fieldId element id of the control
	 * @param {boolean} on      whether the control applies right now
	 */
	function toggle(fieldId, on) {
		var field = document.getElementById(fieldId);
		if (!field) {
			return;
		}
		var row = field.closest ? field.closest('tr') : null;
		if (row) {
			row.hidden = !on;
		}
		if (!on) {
			field.value = '';
		}
		field.disabled = !on;
	}

	function bindAllDay() {
		var box = document.getElementById('adct-pi-field-all_day');
		if (!box) {
			return;
		}
		// The validator already drops the times for an all-day event, so the
		// script only has to make that visible. The values stay in the markup
		// so unticking the box does not silently lose what was there.
		['adct-pi-field-event_time', 'adct-pi-field-event_end_time'].forEach(function (id) {
			toggle(id, !box.checked);
		});
		box.addEventListener('change', function () {
			bindAllDay();
		});
	}

	function bindCustomRule() {
		var preset = document.getElementById('adct-pi-field-recurrence_preset');
		if (!preset) {
			return;
		}
		var apply = function () {
			toggle('adct-pi-field-recurrence_custom', preset.value === 'custom');
		};
		preset.addEventListener('change', apply);
		apply();
	}

	function init() {
		bindAllDay();
		bindCustomRule();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
