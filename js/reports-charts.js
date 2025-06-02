document.addEventListener('DOMContentLoaded', function () {
    // Total Attendees by Region Chart
    if (typeof regionAttendeesChartData !== 'undefined') {
        const ctx = document.getElementById('regionAttendeesChart').getContext('2d');
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: regionAttendeesChartData.labels,
                datasets: [{
                    label: 'Total Attendees',
                    data: regionAttendeesChartData.values,
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
});