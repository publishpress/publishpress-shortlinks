(function($, window, document) {
    'use strict';

    if (typeof tinypressAnalytics === 'undefined') {
        return;
    }

    let chartEl = document.querySelector('#chart-timeline');
    let filterControls = document.querySelector('[data-chart-filter]');
    let dateRangeSelect = document.querySelector('#tinypress-analytics-date-range');
    let customStartInput = document.querySelector('#tinypress-analytics-custom-start');
    let customEndInput = document.querySelector('#tinypress-analytics-custom-end');
    let applyButton = document.querySelector('.tinypress-analytics-apply');
    let rangeDescription = document.querySelector('.tinypress-analytics-range-description');
    let chartDescription = document.querySelector('[data-chart-description]');
    let noDataMessage = document.querySelector('[data-chart-no-data]');
    let resetTextEl = document.querySelector('.reset-text');
    let destinationBody = document.querySelector('[data-destination-performance-body]');
    let destinationEmpty = document.querySelector('[data-destination-performance-empty]');
    let destinationTable = destinationBody ? destinationBody.closest('table') : null;
    let summaryEls = {
        totalClicks: document.querySelector('[data-summary-metric="totalClicks"]'),
        uniqueVisitors: document.querySelector('[data-summary-metric="uniqueVisitors"]'),
        clickDays: document.querySelector('[data-summary-metric="clickDays"]'),
        avgClicksPerDay: document.querySelector('[data-summary-metric="avgClicksPerDay"]')
    };

    if (!chartEl || !filterControls || !dateRangeSelect) {
        return;
    }

    let resetTexts = {
        today: tinypressAnalytics.resetTodayText,
        yesterday: tinypressAnalytics.resetYesterdayText,
        last_7_days: tinypressAnalytics.resetWeekText,
        last_30_days: tinypressAnalytics.resetLast30Text,
        this_month: tinypressAnalytics.resetMonthText,
        last_month: tinypressAnalytics.resetLastMonthText,
        this_year: tinypressAnalytics.resetYearText,
        last_2_years: tinypressAnalytics.resetLast2YearsText,
        custom: tinypressAnalytics.resetCustomText,
        all_time: tinypressAnalytics.resetAllTimeText
    };

    let parseDate = function(dateString) {
        if (!dateString || !/^\d{4}-\d{2}-\d{2}$/.test(dateString)) {
            return null;
        }

        let parts = dateString.split('-');
        return new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));
    };

    function formatDateInput(date) {
        let year = date.getFullYear();
        let month = String(date.getMonth() + 1).padStart(2, '0');
        let day = String(date.getDate()).padStart(2, '0');

        return year + '-' + month + '-' + day;
    }

    let chartDataByDate = {};
    if (tinypressAnalytics.chartDataByDate && typeof tinypressAnalytics.chartDataByDate === 'object') {
        Object.keys(tinypressAnalytics.chartDataByDate).forEach(function(dateKey) {
            if (/^\d{4}-\d{2}-\d{2}$/.test(dateKey)) {
                chartDataByDate[dateKey] = Number(tinypressAnalytics.chartDataByDate[dateKey]) || 0;
            }
        });
    }

    if (!Object.keys(chartDataByDate).length) {
        Object.keys(tinypressAnalytics.chartData || {}).forEach(function(index) {
            let point = tinypressAnalytics.chartData[index];
            let timestamp = Array.isArray(point) ? point[0] : point && (point[0] || point.x || point.date);
            let clicks = Array.isArray(point) ? point[1] : point && (point[1] || point.y || point.clicks);
            let pointDate = typeof timestamp === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(timestamp)
                ? parseDate(timestamp)
                : new Date(Number(timestamp));

            if (pointDate && !isNaN(pointDate.getTime())) {
                chartDataByDate[formatDateInput(pointDate)] = Number(clicks) || 0;
            }
        });
    }

    let visitorsByDate = {};
    if (tinypressAnalytics.visitorsByDate && typeof tinypressAnalytics.visitorsByDate === 'object') {
        Object.keys(tinypressAnalytics.visitorsByDate).forEach(function(dateKey) {
            if (/^\d{4}-\d{2}-\d{2}$/.test(dateKey) && Array.isArray(tinypressAnalytics.visitorsByDate[dateKey])) {
                visitorsByDate[dateKey] = tinypressAnalytics.visitorsByDate[dateKey];
            }
        });
    }

    let destinationDataByDate = {};
    if (tinypressAnalytics.destinationDataByDate && typeof tinypressAnalytics.destinationDataByDate === 'object') {
        Object.keys(tinypressAnalytics.destinationDataByDate).forEach(function(dateKey) {
            if (/^\d{4}-\d{2}-\d{2}$/.test(dateKey) && Array.isArray(tinypressAnalytics.destinationDataByDate[dateKey])) {
                destinationDataByDate[dateKey] = tinypressAnalytics.destinationDataByDate[dateKey];
            }
        });
    }

    let startOfDay = function(date) {
        let nextDate = new Date(date.getTime());
        nextDate.setHours(0, 0, 0, 0);

        return nextDate;
    };

    let endOfDay = function(date) {
        let nextDate = new Date(date.getTime());
        nextDate.setHours(23, 59, 59, 999);

        return nextDate;
    };

    let addDays = function(date, days) {
        let nextDate = new Date(date.getTime());
        nextDate.setDate(nextDate.getDate() + days);

        return nextDate;
    };

    let addYears = function(date, years) {
        let nextDate = new Date(date.getTime());
        nextDate.setFullYear(nextDate.getFullYear() + years);

        return nextDate;
    };

    let getChartBounds = function() {
        let firstDataDate = parseDate(tinypressAnalytics.firstDataDate);
        let lastDataDate = parseDate(tinypressAnalytics.lastDataDate);

        if (firstDataDate && lastDataDate) {
            return {
                start: startOfDay(firstDataDate),
                end: endOfDay(lastDataDate)
            };
        }

        let dataDates = Object.keys(chartDataByDate).sort();
        if (dataDates.length) {
            return {
                start: startOfDay(parseDate(dataDates[0])),
                end: endOfDay(parseDate(dataDates[dataDates.length - 1]))
            };
        }

        let today = parseDate(tinypressAnalytics.todayDate) || new Date();

        return {
            start: startOfDay(today),
            end: endOfDay(today)
        };
    };

    let getRangeForPreset = function(filterName) {
        let today = parseDate(tinypressAnalytics.todayDate) || new Date();
        let startDate = startOfDay(today);
        let endDate = endOfDay(today);

        switch (filterName) {
            case 'yesterday':
                startDate = startOfDay(addDays(today, -1));
                endDate = endOfDay(addDays(today, -1));
                break;
            case 'last_7_days':
                startDate = startOfDay(addDays(today, -6));
                break;
            case 'last_30_days':
                startDate = startOfDay(addDays(today, -29));
                break;
            case 'this_month':
                startDate = startOfDay(new Date(today.getFullYear(), today.getMonth(), 1));
                break;
            case 'last_month':
                startDate = startOfDay(new Date(today.getFullYear(), today.getMonth() - 1, 1));
                endDate = endOfDay(new Date(today.getFullYear(), today.getMonth(), 0));
                break;
            case 'this_year':
                startDate = startOfDay(new Date(today.getFullYear(), 0, 1));
                break;
            case 'last_2_years':
                startDate = startOfDay(addDays(addYears(today, -2), 1));
                break;
            case 'custom':
                startDate = startOfDay(parseDate(customStartInput ? customStartInput.value : '') || parseDate(tinypressAnalytics.defaultCustomStart) || addDays(today, -29));
                endDate = endOfDay(parseDate(customEndInput ? customEndInput.value : '') || parseDate(tinypressAnalytics.defaultCustomEnd) || today);

                if (startDate.getTime() > endDate.getTime()) {
                    let originalStart = startDate;
                    startDate = startOfDay(endDate);
                    endDate = endOfDay(originalStart);
                }
                break;
            case 'all_time':
                return getChartBounds();
            case 'today':
            default:
                filterName = 'today';
                break;
        }

        return {
            start: startDate,
            end: endDate
        };
    };

    let getPeriodType = function(startDate, endDate) {
        let dayCount = Math.floor((startOfDay(endDate).getTime() - startOfDay(startDate).getTime()) / 86400000) + 1;

        if (dayCount <= 62) {
            return 'day';
        }

        if (dayCount <= 180) {
            return 'week';
        }

        if (dayCount <= 730) {
            return 'month';
        }

        return 'year';
    };

    let getNextPeriod = function(startDate, endDate, periodType) {
        let periodEnd = startOfDay(startDate);

        switch (periodType) {
            case 'week':
                periodEnd = addDays(startDate, 6);
                break;
            case 'month':
                periodEnd = new Date(startDate.getFullYear(), startDate.getMonth() + 1, 0);
                break;
            case 'year':
                periodEnd = new Date(startDate.getFullYear(), 11, 31);
                break;
        }

        if (periodEnd.getTime() > endDate.getTime()) {
            periodEnd = startOfDay(endDate);
        }

        return {
            start: startOfDay(startDate),
            end: periodEnd
        };
    };

    let formatDisplayDate = function(date) {
        try {
            return new Intl.DateTimeFormat(undefined, {
                year: 'numeric',
                month: 'short',
                day: 'numeric'
            }).format(date);
        } catch (e) {
            return formatDateInput(date);
        }
    };

    let formatLabelDate = function(date) {
        try {
            return new Intl.DateTimeFormat(undefined, {
                month: 'short',
                day: 'numeric'
            }).format(date);
        } catch (e) {
            return formatDateInput(date).slice(5);
        }
    };

    let formatMonthLabel = function(date) {
        try {
            return new Intl.DateTimeFormat(undefined, {
                month: 'short',
                year: 'numeric'
            }).format(date);
        } catch (e) {
            return formatDateInput(date).slice(0, 7);
        }
    };

    let getPeriodLabel = function(period, periodType) {
        switch (periodType) {
            case 'week':
                return formatLabelDate(period.start) + ' - ' + formatLabelDate(period.end);
            case 'month':
                return formatMonthLabel(period.start);
            case 'year':
                return String(period.start.getFullYear());
            case 'day':
            default:
                return formatLabelDate(period.start);
        }
    };

    let getPeriodTitle = function(period, periodType) {
        if (periodType === 'day') {
            return formatDisplayDate(period.start);
        }

        return formatDisplayDate(period.start) + ' - ' + formatDisplayDate(period.end);
    };

    let buildPeriodData = function(range) {
        let periodType = getPeriodType(range.start, range.end);
        let periods = [];
        let current = startOfDay(range.start);
        let end = startOfDay(range.end);

        while (current.getTime() <= end.getTime()) {
            let period = getNextPeriod(current, end, periodType);
            let clicks = 0;
            let periodDay = startOfDay(period.start);

            while (periodDay.getTime() <= period.end.getTime()) {
                clicks += chartDataByDate[formatDateInput(periodDay)] || 0;
                periodDay = addDays(periodDay, 1);
            }

            periods.push({
                label: getPeriodLabel(period, periodType),
                title: getPeriodTitle(period, periodType),
                period: periodType,
                clicks: clicks
            });

            current = addDays(period.end, 1);
        }

        return periods;
    };

    let buildRangeStats = function(range) {
        let current = startOfDay(range.start);
        let end = startOfDay(range.end);
        let totalClicks = 0;
        let clickDays = 0;
        let dayCount = 0;
        let uniqueVisitors = {};

        while (current.getTime() <= end.getTime()) {
            let dateKey = formatDateInput(current);
            let clicks = chartDataByDate[dateKey] || 0;

            totalClicks += clicks;
            dayCount++;

            if (clicks > 0) {
                clickDays++;
            }

            (visitorsByDate[dateKey] || []).forEach(function(visitorKey) {
                uniqueVisitors[visitorKey] = true;
            });

            current = addDays(current, 1);
        }

        return {
            totalClicks: totalClicks,
            uniqueVisitors: Object.keys(uniqueVisitors).length,
            clickDays: clickDays,
            dayCount: dayCount,
            avgClicksPerClickDay: clickDays > 0 ? totalClicks / clickDays : 0
        };
    };

    let formatNumber = function(value, decimals) {
        let options = {};

        if (decimals) {
            options.minimumFractionDigits = decimals;
            options.maximumFractionDigits = decimals;
        }

        try {
            return Number(value).toLocaleString(undefined, options);
        } catch (e) {
            return String(value);
        }
    };

    let updateSummaryCards = function(stats) {
        if (summaryEls.totalClicks) {
            summaryEls.totalClicks.textContent = formatNumber(stats.totalClicks, 0);
        }
        if (summaryEls.uniqueVisitors) {
            summaryEls.uniqueVisitors.textContent = formatNumber(stats.uniqueVisitors, 0);
        }
        if (summaryEls.clickDays) {
            summaryEls.clickDays.textContent = formatNumber(stats.clickDays, 0);
        }
        if (summaryEls.avgClicksPerDay) {
            summaryEls.avgClicksPerDay.textContent = formatNumber(stats.avgClicksPerClickDay, 1);
        }
    };

    let renderDestinationPerformance = function(range) {
        if (!destinationBody) {
            return;
        }

        let routes = {};
        let totalClicks = 0;
        let current = startOfDay(range.start);
        let end = startOfDay(range.end);

        while (current.getTime() <= end.getTime()) {
            let dateKey = formatDateInput(current);

            (destinationDataByDate[dateKey] || []).forEach(function(route) {
                let routeId = route.id || 'legacy';
                let destination = route.destination || '';
                let key = routeId + '|' + destination;
                let clicks = Number(route.clicks) || 0;

                if (!routes[key]) {
                    routes[key] = {
                        label: route.label || routeId,
                        destination: destination,
                        clicks: 0
                    };
                }

                routes[key].clicks += clicks;
                totalClicks += clicks;
            });

            current = addDays(current, 1);
        }

        let sortedRoutes = Object.keys(routes).map(function(key) {
            return routes[key];
        }).sort(function(a, b) {
            return b.clicks - a.clicks;
        });

        destinationBody.innerHTML = '';

        sortedRoutes.forEach(function(route) {
            let row = document.createElement('tr');
            let labelCell = document.createElement('td');
            let destinationCell = document.createElement('td');
            let clicksCell = document.createElement('td');
            let shareCell = document.createElement('td');

            labelCell.textContent = route.label;

            if (route.destination) {
                let destinationLink = document.createElement('a');
                destinationLink.href = route.destination;
                destinationLink.target = '_blank';
                destinationLink.rel = 'noopener noreferrer';
                destinationLink.textContent = route.destination;
                destinationCell.appendChild(destinationLink);
            } else {
                destinationCell.textContent = '-';
            }

            clicksCell.textContent = formatNumber(route.clicks, 0);
            shareCell.textContent = totalClicks > 0
                ? ((route.clicks / totalClicks) * 100).toFixed(1) + '%'
                : '0%';

            row.appendChild(labelCell);
            row.appendChild(destinationCell);
            row.appendChild(clicksCell);
            row.appendChild(shareCell);
            destinationBody.appendChild(row);
        });

        if (destinationTable) {
            destinationTable.hidden = sortedRoutes.length === 0;
        }
        if (destinationEmpty) {
            destinationEmpty.hidden = sortedRoutes.length > 0;
        }
    };

    let renderChart = function(periodData) {
        let maxClicks = periodData.reduce(function(max, period) {
            return Math.max(max, period.clicks);
        }, 0);
        let hasClicks = maxClicks > 0;

        chartEl.innerHTML = '';
        chartEl.className = 'tinypress-bar-chart';

        if (periodData.length) {
            chartEl.classList.add('tinypress-bar-chart-' + periodData[0].period);
        }

        chartEl.hidden = !hasClicks;
        if (noDataMessage) {
            noDataMessage.hidden = hasClicks;
        }

        if (!hasClicks) {
            return;
        }

        periodData.forEach(function(period) {
            let height = period.clicks > 0 ? (period.clicks / maxClicks) * 100 : 0;
            let clickText = period.clicks === 1 ? tinypressAnalytics.clickSingularText : tinypressAnalytics.clickPluralText;
            let wrapper = document.createElement('div');
            let value = document.createElement('span');
            let bar = document.createElement('div');
            let label = document.createElement('span');

            wrapper.className = 'tinypress-bar-wrapper';
            wrapper.title = period.title + ': ' + period.clicks + ' ' + clickText;

            if (period.clicks > 0) {
                value.className = 'tinypress-bar-value';
                value.textContent = period.clicks.toLocaleString();
                wrapper.appendChild(value);
            }

            bar.className = period.clicks > 0 ? 'tinypress-bar' : 'tinypress-bar tinypress-bar-zero';
            bar.style.height = height + '%';
            wrapper.appendChild(bar);

            label.className = 'tinypress-bar-label';
            label.textContent = period.label;
            wrapper.appendChild(label);

            chartEl.appendChild(wrapper);
        });
    };

    let applyFilter = function(filterName) {
        if (!resetTexts[filterName]) {
            filterName = 'last_30_days';
        }

        let range = getRangeForPreset(filterName);
        let periodData = buildPeriodData(range);
        let rangeStats = buildRangeStats(range);
        let periodType = periodData.length ? periodData[0].period : 'day';

        if (resetTextEl && resetTexts[filterName]) {
            resetTextEl.textContent = resetTexts[filterName];
        }

        dateRangeSelect.value = filterName;
        filterControls.classList.toggle('is-custom-range', filterName === 'custom');

        if (rangeDescription && tinypressAnalytics.showingDataText) {
            rangeDescription.innerHTML = tinypressAnalytics.showingDataText
                .replace('%1$s', '<strong>' + formatDisplayDate(range.start) + '</strong>')
                .replace('%2$s', '<strong>' + formatDisplayDate(range.end) + '</strong>');
        }

        if (chartDescription && tinypressAnalytics.chartDescriptions) {
            chartDescription.textContent = tinypressAnalytics.chartDescriptions[periodType] || '';
        }

        renderChart(periodData);
        updateSummaryCards(rangeStats);
        renderDestinationPerformance(range);

        try {
            localStorage.setItem('tinypress_analytics_filter_' + tinypressAnalytics.postId, filterName);
            if (filterName === 'custom') {
                localStorage.setItem('tinypress_analytics_custom_start_' + tinypressAnalytics.postId, customStartInput ? customStartInput.value : '');
                localStorage.setItem('tinypress_analytics_custom_end_' + tinypressAnalytics.postId, customEndInput ? customEndInput.value : '');
            }
        } catch (e) {}
    };

    let savedFilter = 'last_30_days';
    try {
        let stored = localStorage.getItem('tinypress_analytics_filter_' + tinypressAnalytics.postId);
        if (stored && resetTexts[stored]) {
            savedFilter = stored;
        }
        if (customStartInput) {
            customStartInput.value = localStorage.getItem('tinypress_analytics_custom_start_' + tinypressAnalytics.postId) || customStartInput.value;
        }
        if (customEndInput) {
            customEndInput.value = localStorage.getItem('tinypress_analytics_custom_end_' + tinypressAnalytics.postId) || customEndInput.value;
        }
    } catch (e) {}
    applyFilter(savedFilter);

    dateRangeSelect.addEventListener('change', function() {
        filterControls.classList.toggle('is-custom-range', dateRangeSelect.value === 'custom');
        if (dateRangeSelect.value !== 'custom') {
            applyFilter(dateRangeSelect.value);
        }
    });

    if (applyButton) {
        applyButton.addEventListener('click', function(e) {
            e.preventDefault();
            applyFilter(dateRangeSelect.value);
        });
    }

    let resetButton = document.querySelector('#reset-analytics');
    if (!resetButton) {
        return;
    }

    resetButton.addEventListener('click', function(e) {
        e.preventDefault();

        if (!confirm(tinypressAnalytics.resetConfirmText)) {
            return;
        }

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'tinypress_reset_analytics',
                post_id: tinypressAnalytics.postId,
                period: dateRangeSelect.value || 'last_30_days',
                custom_start: customStartInput ? customStartInput.value : '',
                custom_end: customEndInput ? customEndInput.value : '',
                nonce: tinypressAnalytics.nonce
            },
            success: function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert('Error resetting analytics: ' + (response.data || 'Unknown error'));
                }
            },
            error: function() {
                alert('Error resetting analytics. Please try again.');
            }
        });
    });

})(jQuery, window, document);
