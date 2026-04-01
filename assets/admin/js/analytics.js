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
        },
        tooltip: {
            x: {
                format: 'dd MMM yyyy'
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
        endDate.setHours(23, 59, 59, 999);
        let startDate = new Date();
        startDate.setHours(0, 0, 0, 0);
        let resetText = "Reset Today's Analytics";

        switch (filterName) {
            case 'last_7_days':
                startDate.setDate(startDate.getDate() - 6);
                resetText = "Reset Week's Analytics";
                break;
            case 'last_1_month':
                startDate.setMonth(startDate.getMonth() - 1);
                resetText = "Reset Month's Analytics";
                break;
            case 'last_1_year':
                startDate.setFullYear(startDate.getFullYear() - 1);
                resetText = "Reset Year's Analytics";
                break;
            default:
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
        
        if (!confirm('Are you sure you want to reset the analytics for this period? This action cannot be undone.')) {
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
