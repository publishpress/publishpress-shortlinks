(function($, window, document) {
    'use strict';

    if (typeof tinypressAnalytics === 'undefined') {
        return;
    }

    let options = {
        series: [{
            name: 'Clicks',
            data: tinypressAnalytics.chartData
        }],
        chart: {
            id: 'area-datetime',
            type: 'area',
            height: 350,
            toolbar: {
                show: false
            },
            zoom: {
                enabled: false
            }
        },
        dataLabels: {
            enabled: false
        },
        markers: {
            size: 0,
            style: 'hollow',
        },
        xaxis: {
            type: 'datetime',
        },
        yaxis: {
            min: 0,
            forceNiceScale: true,
            decimalsInFloat: 0,
            labels: {
                formatter: function(val) {
                    return Math.floor(val);
                }
            }
        },
        tooltip: {
            x: {
                format: 'dd MMM yyyy'
            },
            y: {
                formatter: function(value) {
                    return Math.floor(value);
                }
            }
        },
        fill: {
            type: 'gradient',
            gradient: {
                shadeIntensity: 1,
                opacityFrom: 0.7,
                opacityTo: 0.9,
                stops: [0, 100]
            }
        }
    };

    let chart = new ApexCharts(document.querySelector("#chart-timeline"), options);

    let applyFilter = function (filterName) {
        let endDate = new Date();
        let startDate = new Date();
        startDate.setHours(0, 0, 0, 0);
        let resetText = tinypressAnalytics.resetTodayText;

        switch (filterName) {
            case 'last_7_days':
                endDate.setHours(23, 59, 59, 999);
                startDate.setDate(startDate.getDate() - 6);
                resetText = tinypressAnalytics.resetWeekText;
                break;
            case 'last_1_month':
                endDate.setHours(23, 59, 59, 999);
                startDate.setMonth(startDate.getMonth() - 1);
                resetText = tinypressAnalytics.resetMonthText;
                break;
            case 'last_1_year':
                endDate.setHours(23, 59, 59, 999);
                startDate.setFullYear(startDate.getFullYear() - 1);
                resetText = tinypressAnalytics.resetYearText;
                break;
            default:
                endDate.setHours(23, 59, 59, 999);
                filterName = 'today';
                break;
        }

        document.querySelector('.reset-text').textContent = resetText;

        let els = document.querySelectorAll('.date-filter');
        Array.prototype.forEach.call(els, function (el) {
            el.classList.remove('active');
        });
        let activeBtn = document.querySelector('.date-filter.' + filterName.replace(/ /g, '_'));
        if (activeBtn) {
            activeBtn.classList.add('active');
        }

        chart.updateOptions({
            xaxis: {
                min: startDate.getTime(),
                max: endDate.getTime()
            }
        });

        try {
            localStorage.setItem('tinypress_analytics_filter_' + tinypressAnalytics.postId, filterName);
        } catch (e) {}
    };

    chart.render();

    // Restore saved filter or default to Last 1 Month
    let savedFilter = 'last_1_month';
    try {
        let stored = localStorage.getItem('tinypress_analytics_filter_' + tinypressAnalytics.postId);
        if (stored) {
            savedFilter = stored;
        }
    } catch (e) {}
    applyFilter(savedFilter);

    // Filter button click handlers
    document.querySelectorAll('.date-filter').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            let filterName = this.getAttribute('data-filter');
            applyFilter(filterName);
        });
    });

    // Reset analytics functionality
    document.querySelector('#reset-analytics').addEventListener('click', function (e) {
        e.preventDefault();
        
        if (!confirm(tinypressAnalytics.resetConfirmText)) {
            return;
        }
        
        let activeFilter = document.querySelector('.date-filter.active');
        let period = 'today';
        
        if (activeFilter.classList.contains('last_7_days')) {
            period = 'last_7_days';
        } else if (activeFilter.classList.contains('last_1_month')) {
            period = 'last_1_month';
        } else if (activeFilter.classList.contains('last_1_year')) {
            period = 'last_1_year';
        }
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'tinypress_reset_analytics',
                post_id: tinypressAnalytics.postId,
                period: period,
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
