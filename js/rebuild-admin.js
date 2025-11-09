/**
 * Admin JavaScript for InterSoccer Database Rebuild
 * File: assets/js/rebuild-admin.js
 */

jQuery(document).ready(function($) {
    'use strict';
    
    var rebuildManager = {
        isRunning: false,
        statusInterval: null,
        batchDelay: 1000, // Delay between batches in milliseconds
        
        init: function() {
            this.bindEvents();
            this.loadDatabaseStats();
            this.checkRebuildStatus();
            this.startStatusPolling();
        },
        
        bindEvents: function() {
            $('#start-rebuild').on('click', this.handleStartRebuild.bind(this));
            $('#stop-rebuild').on('click', this.handleStopRebuild.bind(this));
            
            // Handle page unload during rebuild
            $(window).on('beforeunload', function() {
                if (rebuildManager.isRunning) {
                    return 'A database rebuild is in progress. Leaving this page may interrupt the process.';
                }
            });
        },
        
        handleStartRebuild: function() {
            if (!confirm(intersoccerRebuild.strings.confirm_rebuild)) {
                return;
            }
            
            this.startRebuild();
        },
        
        handleStopRebuild: function() {
            if (confirm('Are you sure you want to stop the rebuild process?')) {
                this.stopRebuild();
            }
        },
        
        startRebuild: function() {
            this.isRunning = true;
            this.setUIState('starting');
            
            $.ajax({
                url: intersoccerRebuild.ajax_url,
                type: 'POST',
                data: {
                    action: 'intersoccer_start_rebuild',
                    nonce: intersoccerRebuild.nonce
                },
                success: this.handleStartSuccess.bind(this),
                error: this.handleStartError.bind(this),
                timeout: 60000 // 60 second timeout for start
            });
        },
        
        handleStartSuccess: function(response) {
            if (response.success) {
                this.setUIState('running');
                this.updateStatus('Database rebuild started');
                this.updateProgress({
                    processed: 0,
                    total: response.data.total,
                    percentage: 0,
                    current_batch: 0
                });
                
                // Start processing batches
                setTimeout(this.processBatch.bind(this), this.batchDelay);
            } else {
                this.handleError('Failed to start rebuild: ' + (response.data.message || 'Unknown error'));
            }
        },
        
        handleStartError: function(xhr, status, error) {
            this.handleError('Failed to start rebuild process: ' + error);
        },
        
        processBatch: function() {
            if (!this.isRunning) {
                return;
            }
            
            $.ajax({
                url: intersoccerRebuild.ajax_url,
                type: 'POST',
                data: {
                    action: 'intersoccer_rebuild_database_batch',
                    nonce: intersoccerRebuild.nonce
                },
                success: this.handleBatchSuccess.bind(this),
                error: this.handleBatchError.bind(this),
                timeout: 45000 // 45 second timeout per batch
            });
        },
        
        handleBatchSuccess: function(response) {
            if (response.success) {
                var data = response.data;
                
                if (data.completed) {
                    this.completeRebuild(data.message);
                } else {
                    this.updateProgress(data);
                    this.updateStatus(intersoccerRebuild.strings.processing + ' batch ' + data.current_batch + ' - ' + data.message);
                    
                    if (data.errors_count > 0) {
                        this.showErrorCount(data.errors_count);
                    }
                    
                    // Continue with next batch
                    if (this.isRunning) {
                        setTimeout(this.processBatch.bind(this), this.batchDelay);
                    }
                }
            } else {
                this.handleError('Batch processing failed: ' + (response.data.message || 'Unknown error'));
            }
        },
        
        handleBatchError: function(xhr, status, error) {
            if (status === 'timeout') {
                this.handleError('Batch processing timed out. The server may be overloaded.');
            } else {
                this.handleError('AJAX error during batch processing: ' + error);
            }
        },
        
        completeRebuild: function(message) {
            this.isRunning = false;
            this.updateProgress({percentage: 100});
            this.updateStatus(message || intersoccerRebuild.strings.completed);
            
            // Show success state for a few seconds, then reset
            setTimeout(function() {
                rebuildManager.setUIState('idle');
                rebuildManager.loadDatabaseStats();
            }, 3000);
        },
        
        stopRebuild: function() {
            this.isRunning = false;
            this.setUIState('idle');
            this.updateStatus('Rebuild process stopped by user');
        },
        
        setUIState: function(state) {
            var $startBtn = $('#start-rebuild');
            var $stopBtn = $('#stop-rebuild');
            var $progressContainer = $('#progress-container');
            
            switch (state) {
                case 'starting':
                    $startBtn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> Starting...');
                    $progressContainer.show();
                    break;
                    
                case 'running':
                    $startBtn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> Rebuilding...');
                    $stopBtn.show();
                    $progressContainer.show();
                    break;
                    
                case 'idle':
                default:
                    this.isRunning = false;
                    $startBtn.prop('disabled', false).html('<span class="dashicons dashicons-update"></span> Start Database Rebuild');
                    $stopBtn.hide();
                    $progressContainer.hide();
                    break;
            }
        },
        
        updateStatus: function(text) {
            $('#status-text').text(text);
        },
        
        updateProgress: function(data) {
            if (data.percentage !== undefined) {
                $('#progress-fill').css('width', data.percentage + '%');
                $('#progress-percentage').text(data.percentage + '%');
            }
            
            if (data.processed !== undefined && data.total !== undefined) {
                $('#progress-details').text(data.processed + ' of ' + data.total + ' orders processed');
            }
        },
        
        startStatusPolling: function() {
            // Check status every 10 seconds
            this.statusInterval = setInterval(this.checkRebuildStatus.bind(this), 10000);
        },
        
        checkRebuildStatus: function() {
            $.ajax({
                url: intersoccerRebuild.ajax_url,
                type: 'POST',
                data: {
                    action: 'intersoccer_get_rebuild_status',
                    nonce: intersoccerRebuild.nonce
                },
                success: this.handleStatusCheck.bind(this),
                timeout: 15000
            });
        },
        
        handleStatusCheck: function(response) {
            if (response.success) {
                var data = response.data;
                
                if (data.status === 'in_progress' && !this.isRunning) {
                    // Another process is running the rebuild
                    this.isRunning = true;
                    this.setUIState('running');
                    this.updateProgress(data);
                    this.updateStatus('Rebuild in progress...');
                } else if (data.status === 'completed' && this.isRunning) {
                    this.completeRebuild('Database rebuild completed successfully!');
                } else if (data.status === 'idle' && this.isRunning) {
                    this.setUIState('idle');
                    this.updateStatus('Ready to rebuild database');
                }
            }
        },
        
        showErrorCount: function(count) {
            if (count > 0) {
                $('#error-log-section').show();
                this.loadErrorLog();
            }
        },
        
        loadErrorLog: function() {
            $.ajax({
                url: intersoccerRebuild.ajax_url,
                type: 'POST',
                data: {
                    action: 'intersoccer_get_rebuild_errors',
                    nonce: intersoccerRebuild.nonce
                },
                success: function(response) {
                    if (response.success && response.data.errors) {
                        var html = '';
                        response.data.errors.forEach(function(error) {
                            html += '<div class="error-item">';
                            html += '<div class="error-order">Order #' + error.order_id + '</div>';
                            html += '<div class="error-message">' + error.error + '</div>';
                            html += '<div class="error-time">' + error.timestamp + '</div>';
                            html += '</div>';
                        });
                        $('#error-log-content').html(html || '<p>No detailed error information available.</p>');
                    } else {
                        $('#error-log-content').html('<p>Errors occurred during processing. Check debug.log for details.</p>');
                    }
                }
            });
        },
        
        loadDatabaseStats: function() {
            $.ajax({
                url: intersoccerRebuild.ajax_url,
                type: 'POST',
                data: {
                    action: 'intersoccer_get_db_stats',
                    nonce: intersoccerRebuild.nonce
                },
                success: this.displayStats.bind(this),
                error: function() {
                    $('#db-stats').html('<p>Unable to load statistics</p>');
                }
            });
        },
        
        displayStats: function(response) {
            if (!response.success) {
                $('#db-stats').html('<p>Unable to load statistics</p>');
                return;
            }
            
            var stats = response.data;
            var html = '<div class="stats-grid">';
            
            var statItems = [
                { key: 'total_orders', label: 'Total Orders' },
                { key: 'total_roster_entries', label: 'Roster Entries' },
                { key: 'camps', label: 'Camp Bookings' },
                { key: 'courses', label: 'Course Bookings' },
                { key: 'last_rebuild', label: 'Last Rebuild' }
            ];
            
            statItems.forEach(function(item) {
                if (stats[item.key] !== undefined) {
                    html += '<div class="stat-item">';
                    html += '<span class="stat-number">' + stats[item.key] + '</span>';
                    html += '<span class="stat-label">' + item.label + '</span>';
                    html += '</div>';
                }
            });
            
            html += '</div>';
            $('#db-stats').html(html);
        },
        
        handleError: function(message) {
            this.isRunning = false;
            this.setUIState('idle');
            this.updateStatus('Error: ' + message);
            
            // Also log to console for debugging
            console.error('InterSoccer Rebuild Error:', message);
        }
    };
    
    // Initialize the rebuild manager
    rebuildManager.init();

    // Handle database upgrade form submission via AJAX
    (function handleDatabaseUpgrade() {
        var $form = $('#intersoccer-upgrade-form');
        if (!$form.length) {
            return;
        }

        var $button = $form.find('input[type="submit"]');
        var originalLabel = $button.val();
        var $status = $('#intersoccer-rebuild-status');
        var strings = (window.intersoccerRebuild && intersoccerRebuild.strings) ? intersoccerRebuild.strings : {};
        var upgradingText = strings.upgrading || 'Upgrading...';
        var successText = strings.upgrade_success || 'Database upgrade completed successfully.';
        var failureText = strings.upgrade_failed || 'Database upgrade failed.';
        var networkText = strings.upgrade_failed_network || 'Database upgrade failed due to a network error.';

        $form.on('submit', function(event) {
            event.preventDefault();

            $button.prop('disabled', true).val(upgradingText);
            $status.removeClass('notice notice-success notice-error').hide().text('');

            $.ajax({
                url: $form.attr('action'),
                method: 'POST',
                data: $form.serialize(),
                dataType: 'json'
            }).done(function(response) {
                var message = (response && response.data && response.data.message) ? response.data.message : '';

                if (response && response.success) {
                    $status.removeClass('notice-error').addClass('notice notice-success').text(message || successText).show();
                } else {
                    $status.removeClass('notice-success').addClass('notice notice-error').text(message || failureText).show();
                }
            }).fail(function() {
                $status.removeClass('notice-success').addClass('notice notice-error').text(networkText).show();
            }).always(function() {
                $button.prop('disabled', false).val(originalLabel);
            });
        });
    })();
    
    // Add CSS animation for spinning icons
    var style = $('<style>').text(`
        .spin {
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        .progress-fill {
            transition: width 0.5s ease-in-out;
        }
        .error-item {
            border-left: 4px solid #d63638;
            margin-bottom: 10px;
        }
        .error-time {
            font-size: 11px;
            color: #646970;
            margin-top: 5px;
        }
    `);
    $('head').append(style);
});