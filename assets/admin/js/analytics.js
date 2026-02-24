(function($, window, document) {
    'use strict';

    if (typeof tinypressAnalytics === 'undefined') {
        return;
    }

    let options = {
        series: [{
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
        annotations: {
            yaxis: [{
                y: 30,
                borderColor: '#999',
                label: {
                    show: true,
                    text: 'Support',
                    style: {
                        color: "#fff",
                        background: '#00E396'
                    }
                }
            }],
            xaxis: [{
                x: new Date('14 Nov 2012').getTime(),
                borderColor: '#999',
                yAxisIndex: 0,
                label: {
                    show: true,
                    text: 'Rally',
                    style: {
                        color: "#fff",
                        background: '#775DD0'
                    }
                }
            }]
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
    let today_date = new Date();
    
    let reset_css_classes = function (activeEl) {
        let els = document.querySelectorAll('.date-filter');
        Array.prototype.forEach.call(els, function (el) {
            el.classList.remove('active');
        });
        activeEl.target.classList.add('active');
    };

    chart.render();

    // Set default view to Today
    let defaultEndDate = new Date();
    defaultEndDate.setHours(23, 59, 59, 999);
    let defaultStartDate = new Date();
    defaultStartDate.setHours(0, 0, 0, 0);
    chart.zoomX(
        defaultStartDate.getTime(),
        defaultEndDate.getTime()
    );

    // Today filter
    document.querySelector('.today').addEventListener('click', function (e) {
        reset_css_classes(e);
        document.querySelector('.reset-text').textContent = "Reset Today's Analytics";
        
        let endDate = new Date();
        endDate.setHours(23, 59, 59, 999);
        let startDate = new Date();
        startDate.setHours(0, 0, 0, 0);

        chart.zoomX(
            startDate.getTime(),
            endDate.getTime()
        );
    });

    // Last 7 Days filter
    document.querySelector('.last_7_days').addEventListener('click', function (e) {
        reset_css_classes(e);
        document.querySelector('.reset-text').textContent = "Reset Week's Analytics";
        
        let endDate = new Date();
        endDate.setHours(23, 59, 59, 999);
        let startDate = new Date();
        startDate.setDate(startDate.getDate() - 6);
        startDate.setHours(0, 0, 0, 0);

        chart.zoomX(
            startDate.getTime(),
            endDate.getTime()
        );
    });

    // Last 1 Month filter
    document.querySelector('.last_1_month').addEventListener('click', function (e) {
        reset_css_classes(e);
        document.querySelector('.reset-text').textContent = "Reset Month's Analytics";
        
        let endDate = new Date();
        endDate.setHours(23, 59, 59, 999);
        let startDate = new Date();
        startDate.setMonth(startDate.getMonth() - 1);
        startDate.setHours(0, 0, 0, 0);

        chart.zoomX(
            startDate.getTime(),
            endDate.getTime()
        );
    });

    // Last 1 Year filter
    document.querySelector('.last_1_year').addEventListener('click', function (e) {
        reset_css_classes(e);
        document.querySelector('.reset-text').textContent = "Reset Year's Analytics";
        
        let endDate = new Date();
        endDate.setHours(23, 59, 59, 999);
        let startDate = new Date();
        startDate.setFullYear(startDate.getFullYear() - 1);
        startDate.setHours(0, 0, 0, 0);

        chart.zoomX(
            startDate.getTime(),
            endDate.getTime()
        );
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
