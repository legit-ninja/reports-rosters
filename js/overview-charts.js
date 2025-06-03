document.addEventListener('DOMContentLoaded', function () {
    // Current Attendance by Venue Chart (Bar Chart)
    if (typeof currentVenueChartData !== 'undefined') {
        const ctxCurrentVenue = document.getElementById('currentVenueChart').getContext('2d');
        new Chart(ctxCurrentVenue, {
            type: 'bar',
            data: {
                labels: currentVenueChartData.labels,
                datasets: [{
                    label: 'Current Attendees',
                    data: currentVenueChartData.values,
                    backgroundColor: 'rgba(153, 102, 255, 0.6)',
                    borderColor: 'rgba(153, 102, 255, 1)',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Number of Attendees'
                        },
                        ticks: {
                            stepSize: 1,
                            callback: function(value) {
                                return Number.isInteger(value) ? value : null;
                            }
                        }
                    },
                    x: {
                        title: {
                            display: true,
                            text: 'Venue'
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: true,
                        position: 'top'
                    }
                }
            }
        });
    }

    // Attendees by Region Chart (Bar Chart)
    if (typeof regionChartData !== 'undefined') {
        const ctxRegion = document.getElementById('regionChart').getContext('2d');
        new Chart(ctxRegion, {
            type: 'bar',
            data: {
                labels: regionChartData.labels,
                datasets: [{
                    label: 'Total Attendees',
                    data: regionChartData.values,
                    backgroundColor: 'rgba(54, 162, 235, 0.6)',
                    borderColor: 'rgba(54, 162, 235, 1)',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Number of Attendees'
                        },
                        ticks: {
                            stepSize: 1,
                            callback: function(value) {
                                return Number.isInteger(value) ? value : null;
                            }
                        }
                    },
                    x: {
                        title: {
                            display: true,
                            text: 'Region'
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: true,
                        position: 'top'
                    }
                }
            }
        });
    }

    // Age Distribution Chart (Bar Chart)
    if (typeof ageChartData !== 'undefined') {
        const ctxAge = document.getElementById('ageChart').getContext('2d');
        new Chart(ctxAge, {
            type: 'bar',
            data: {
                labels: ageChartData.labels,
                datasets: [{
                    label: 'Number of Attendees',
                    data: ageChartData.values,
                    backgroundColor: 'rgba(255, 99, 132, 0.6)',
                    borderColor: 'rgba(255, 99, 132, 1)',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Number of Attendees'
                        },
                        ticks: {
                            stepSize: 1,
                            callback: function(value) {
                                return Number.isInteger(value) ? value : null;
                            }
                        }
                    },
                    x: {
                        title: {
                            display: true,
                            text: 'Age Group'
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: true,
                        position: 'top'
                    }
                }
            }
        });
    }

    // Gender Distribution Chart (Pie Chart)
    if (typeof genderChartData !== 'undefined') {
        const ctxGender = document.getElementById('genderChart').getContext('2d');
        new Chart(ctxGender, {
            type: 'pie',
            data: {
                labels: genderChartData.labels,
                datasets: [{
                    label: 'Gender Distribution',
                    data: genderChartData.values,
                    backgroundColor: [
                        'rgba(54, 162, 235, 0.6)',
                        'rgba(255, 99, 132, 0.6)',
                        'rgba(255, 206, 86, 0.6)',
                    ],
                    borderColor: [
                        'rgba(54, 162, 235, 1)',
                        'rgba(255, 99, 132, 1)',
                        'rgba(255, 206, 86, 1)',
                    ],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'top',
                    },
                    title: {
                        display: true,
                        text: 'Gender Distribution'
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let label = context.label || '';
                                if (label) {
                                    label += ': ';
                                }
                                label += Math.round(context.parsed);
                                return label;
                            }
                        }
                    }
                }
            }
        });
    }

    // Weekly Attendance Trends Chart (Line Chart)
    if (typeof weeklyTrendsChartData !== 'undefined') {
        const ctxWeekly = document.getElementById('weeklyTrendsChart').getContext('2d');
        new Chart(ctxWeekly, {
            type: 'line',
            data: {
                labels: weeklyTrendsChartData.labels,
                datasets: [{
                    label: 'Total Attendees',
                    data: weeklyTrendsChartData.values,
                    fill: false,
                    borderColor: 'rgba(75, 192, 192, 1)',
                    tension: 0.1
                }]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Number of Attendees'
                        },
                        ticks: {
                            stepSize: 1,
                            callback: function(value) {
                                return Number.isInteger(value) ? value : null;
                            }
                        }
                    },
                    x: {
                        title: {
                            display: true,
                            text: 'Week Starting'
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: true,
                        position: 'top'
                    }
                }
            }
        });
    }
});
