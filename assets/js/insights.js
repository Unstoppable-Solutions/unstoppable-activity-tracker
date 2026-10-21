/**
 * Unstoppable Activity Tracker — Insights front-end.
 *
 * Depends on jQuery (for AJAX) and Chart.js (bundled locally as chart.min.js).
 * Renders four cards: events over time, events by category, top users, security highlights.
 */
(function ($) {
	'use strict';

	if (typeof window.uatr_insights === 'undefined' || typeof window.Chart === 'undefined') {
		return;
	}

	var config = window.uatr_insights;
	var i18n = config.i18n || {};
	var currentRange = '7d';
	var chartTimeline = null;
	var chartCategory = null;

	// Gold accent (matches admin menu branding) and complementary palette for categories.
	var GOLD = '#f0b849';
	var CATEGORY_COLORS = ['#f0b849', '#2271b1', '#00a32a', '#d63638', '#8c1d1f', '#7c3aed', '#666666'];

	$(function () {
		if ($('#uatr-chart-timeline').length === 0) {
			// No canvases — empty state, nothing to load.
			return;
		}

		bindRangeButtons();
		loadRange(currentRange);
	});

	function bindRangeButtons() {
		$('.uatr-range-btn').on('click', function () {
			var range = $(this).data('range');
			if (!range || range === currentRange) {
				return;
			}
			$('.uatr-range-btn').removeClass('uatr-range-active');
			$(this).addClass('uatr-range-active');
			currentRange = range;
			loadRange(range);
		});
	}

	function loadRange(range) {
		$('.uatr-range-loading').text(i18n.loading || 'Loading…');
		$('.uatr-range-btn').prop('disabled', true);

		$.ajax({
			url: config.ajax_url,
			type: 'POST',
			dataType: 'json',
			data: {
				action: 'uatr_get_insights',
				nonce: config.nonce,
				range: range
			}
		}).done(function (response) {
			if (!response || !response.success) {
				var msg = (response && response.data && response.data.message)
					? response.data.message
					: (i18n.error || 'Could not load insights.');
				showError(msg);
				return;
			}
			renderAll(response.data);
			$('.uatr-range-loading').text('');
		}).fail(function () {
			showError(i18n.error || 'Could not load insights.');
		}).always(function () {
			$('.uatr-range-btn').prop('disabled', false);
		});
	}

	function showError(msg) {
		$('.uatr-range-loading').text(msg).addClass('uatr-error');
		setTimeout(function () {
			$('.uatr-range-loading').removeClass('uatr-error');
		}, 5000);
	}

	function renderAll(data) {
		renderTimeline(data.events_over_time || []);
		renderCategory(data.by_category || []);
		renderTopUsers(data.top_users || []);
		renderSecurityHighlights(data.security_highlights || {});
	}

	// ---------- Card 1: timeline ----------
	function renderTimeline(rows) {
		var labels = rows.map(function (r) { return r.label; });
		var counts = rows.map(function (r) { return r.count; });

		if (chartTimeline) {
			chartTimeline.data.labels = labels;
			chartTimeline.data.datasets[0].data = counts;
			chartTimeline.update();
			return;
		}

		var ctx = document.getElementById('uatr-chart-timeline');
		chartTimeline = new Chart(ctx, {
			type: 'bar',
			data: {
				labels: labels,
				datasets: [{
					label: i18n.events || 'Events',
					data: counts,
					backgroundColor: GOLD,
					borderColor: GOLD,
					borderWidth: 1,
					borderRadius: 3
				}]
			},
			options: {
				responsive: true,
				maintainAspectRatio: false,
				plugins: { legend: { display: false } },
				scales: {
					x: { grid: { display: false } },
					y: { beginAtZero: true, ticks: { precision: 0 } }
				}
			}
		});
	}

	// ---------- Card 2: category donut ----------
	function renderCategory(rows) {
		var labels = rows.map(function (r) { return capitalize(r.object); });
		var counts = rows.map(function (r) { return r.count; });
		var colors = labels.map(function (_, i) { return CATEGORY_COLORS[i % CATEGORY_COLORS.length]; });

		if (chartCategory) {
			chartCategory.data.labels = labels;
			chartCategory.data.datasets[0].data = counts;
			chartCategory.data.datasets[0].backgroundColor = colors;
			chartCategory.update();
			return;
		}

		var ctx = document.getElementById('uatr-chart-category');
		chartCategory = new Chart(ctx, {
			type: 'doughnut',
			data: {
				labels: labels,
				datasets: [{
					data: counts,
					backgroundColor: colors,
					borderWidth: 2,
					borderColor: '#fff'
				}]
			},
			options: {
				responsive: true,
				maintainAspectRatio: false,
				plugins: {
					legend: { position: 'right', labels: { boxWidth: 12, padding: 8 } }
				},
				cutout: '55%'
			}
		});
	}

	// ---------- Card 3: top users ----------
	function renderTopUsers(users) {
		var $container = $('#uatr-top-users');
		if (users.length === 0) {
			$container.html('<p class="uatr-empty">' + escapeHtml(i18n.no_users || 'No user activity in this range.') + '</p>');
			return;
		}
		var html = '<ol class="uatr-top-users">';
		users.forEach(function (u) {
			html += '<li>';
			html += '<span class="uatr-user-name">' + escapeHtml(u.username) + '</span>';
			html += '<span class="uatr-user-count">' + formatNumber(u.event_count) + ' ' + escapeHtml(i18n.events_short || 'events') + '</span>';
			html += '<span class="uatr-user-last">' + escapeHtml(i18n.last_active || 'Last active') + ': ' + escapeHtml(formatRelativeTime(u.last_active)) + '</span>';
			html += '</li>';
		});
		html += '</ol>';
		$container.html(html);
	}

	// ---------- Card 4: security highlights ----------
	function renderSecurityHighlights(s) {
		var items = [
			{ key: 'failed_logins',        label: i18n.failed_logins        || 'Failed logins',        severity: 'warning' },
			{ key: 'new_users',            label: i18n.new_users            || 'New users created',    severity: 'notice'  },
			{ key: 'deleted_users',        label: i18n.deleted_users        || 'Users deleted',        severity: 'warning' },
			{ key: 'role_changes',         label: i18n.role_changes         || 'Role changes',         severity: 'notice'  },
			{ key: 'plugin_installs',      label: i18n.plugin_installs      || 'Plugins installed',    severity: 'notice'  },
			{ key: 'plugin_activations',   label: i18n.plugin_activations   || 'Plugins activated',    severity: 'info'    },
			{ key: 'plugin_deactivations', label: i18n.plugin_deactivations || 'Plugins deactivated',  severity: 'info'    },
			{ key: 'core_updates',         label: i18n.core_updates         || 'Core updates',         severity: 'info'    }
		];

		var html = '<div class="uatr-security-grid">';
		items.forEach(function (item) {
			var value = s[item.key] || 0;
			var isZero = (value === 0);
			html += '<div class="uatr-security-item uatr-sev-' + item.severity + (isZero ? ' uatr-zero' : '') + '">';
			html += '<div class="uatr-security-value">' + formatNumber(value) + '</div>';
			html += '<div class="uatr-security-label">' + escapeHtml(item.label) + '</div>';
			html += '</div>';
		});
		html += '</div>';
		$('#uatr-security-highlights').html(html);
	}

	// ---------- helpers ----------
	function capitalize(s) {
		s = String(s || '');
		return s.charAt(0).toUpperCase() + s.slice(1);
	}

	function formatNumber(n) {
		return Number(n || 0).toLocaleString();
	}

	function formatRelativeTime(unixTs) {
		if (!unixTs) return '';
		var secondsAgo = Math.floor((Date.now() / 1000) - unixTs);
		if (secondsAgo < 60)     return (i18n.just_now      || 'just now');
		if (secondsAgo < 3600)   return Math.floor(secondsAgo / 60)     + ' ' + (i18n.min_ago  || 'min ago');
		if (secondsAgo < 86400)  return Math.floor(secondsAgo / 3600)   + ' ' + (i18n.hour_ago || 'hours ago');
		if (secondsAgo < 604800) return Math.floor(secondsAgo / 86400)  + ' ' + (i18n.day_ago  || 'days ago');
		return Math.floor(secondsAgo / 604800) + ' ' + (i18n.week_ago || 'weeks ago');
	}

	function escapeHtml(s) {
		return String(s == null ? '' : s)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#39;');
	}

})(jQuery);
