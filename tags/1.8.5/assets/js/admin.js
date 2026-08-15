jQuery(document).ready(function($) {
    var currentPage = 1;
    var searchQuery = '';
    var isLoading = false;
    var counts = { red:0, orange:0, green:0, gray:0 };

    $('.tab-btn').on('click', function() {
        $('.tab-btn').removeClass('active');
        $(this).addClass('active');
        $('.erdo-content').removeClass('active');
        var target = $(this).data('target');
        $('#' + target).addClass('active');

        if(target === 'tab-gray') {
            $('#erdo-bulk-webp-btn').addClass('hidden');
            $('#erdo-bulk-delete-btn').removeClass('hidden');
        } else {
            $('#erdo-bulk-webp-btn').removeClass('hidden');
            $('#erdo-bulk-delete-btn').addClass('hidden');
        }
    });

    function loadStats() {
        $.post(erdo_vars.ajax_url, { action: 'erdo_get_stats', nonce: erdo_vars.nonce }, function(res) {
            if(res.success) {
                var d = res.data;
                $('#stat-total-count').text(d.total);
                $('#stat-opt-score').text(d.opt_score + '%');
                $('#stat-seo-score').text(d.seo_score + '%');
                $('#stat-total-size').text(d.total > 0 ? 'Images scanned' : 'No images');
                $('#stat-total-saved').text(d.total_saved);
                updateChart('chart-opt-ring', d.opt_score, '#2271b1');
                updateChart('chart-seo-ring', d.seo_score, '#46b450');
            }
        });
    }

    function updateChart(id, percent, color) {
        $('#' + id).css('background', 'conic-gradient(' + color + ' 0% ' + percent + '%, #f0f0f1 ' + percent + '% 100%)');
        $('#' + id + ' span').text(percent + '%');
    }

    function loadImages(reset = false) {
        if(isLoading) return;
        isLoading = true;
        var btn = $('#erdo-load-more-btn');
        btn.text('Loading...').attr('disabled', 'disabled');

        if(reset) {
            currentPage = 1;
            $('.erdo-grid').html('');
            $('#erdo-no-more-msg').addClass('hidden');
            counts = { red:0, orange:0, green:0, gray:0 };
            updateCounts();
        }

        $.post(erdo_vars.ajax_url, { 
            action: 'erdo_load_images', 
            page: currentPage, 
            search: searchQuery,
            nonce: erdo_vars.nonce 
        }, function(res) {
            if(res.success) {
                $('#tab-red .erdo-grid').append(res.data.html.red);
                $('#tab-orange .erdo-grid').append(res.data.html.orange);
                $('#tab-green .erdo-grid').append(res.data.html.green);
                $('#tab-gray .erdo-grid').append(res.data.html.gray);

                counts.red += res.data.counts.red;
                counts.orange += res.data.counts.orange;
                counts.green += res.data.counts.green;
                counts.gray += res.data.counts.gray;
                updateCounts();
                $('#stat-unused-count').text(counts.gray);

                if ( currentPage >= res.data.max_pages || res.data.found === 0 ) {
                    btn.addClass('hidden');
                    $('#erdo-no-more-msg').removeClass('hidden');
                } else {
                    btn.removeClass('hidden');
                    $('#erdo-no-more-msg').addClass('hidden');
                    currentPage++;
                }
            }
            btn.text('Load More Images').removeAttr('disabled');
            isLoading = false;
        }).fail(function() { btn.text('Error').removeAttr('disabled'); isLoading = false; });
    }

    function updateCounts() {
        $('#count-red').text(counts.red);
        $('#count-orange').text(counts.orange);
        $('#count-green').text(counts.green);
        $('#count-gray').text(counts.gray);
    }

    loadImages(true);
    loadStats();

    $('#erdo-load-more-btn').on('click', function() { loadImages(false); });
    
    var searchTimer;
    $('#erdo-search-input').on('keyup', function(e) {
        clearTimeout(searchTimer);
        var val = $(this).val();
        if(e.key === 'Enter' || val === '') { searchQuery = val; loadImages(true); return; }
        searchTimer = setTimeout(function() { if(searchQuery !== val) { searchQuery = val; loadImages(true); } }, 800);
    });

    $(document).on('click', '.erdo-hotspot', function(e) { e.stopPropagation(); $(this).next('.erdo-help-content').fadeToggle(150); });
    
    $(document).on('click', '.erdo-card', function() { 
        var id = $(this).data('id'); 
        $('#erdo-modal-overlay').removeClass('hidden'); 
        $('#erdo-modal-content').html('<p style="text-align:center; padding:50px;">Analyzing image...</p>'); 
        // Düzenlendi: action: 'erdo_get_detail'
        $.post(erdo_vars.ajax_url, { action: 'erdo_get_detail', id: id, nonce: erdo_vars.nonce }, function(res) { $('#erdo-modal-content').html(res); }); 
    });

    $(document).on('click', '#erdo-save-btn', function() { 
        var btn = $(this); 
        // Düzenlendi: action=erdo_save_data
        var data = $('#erdo-audit-form').serialize() + '&action=erdo_save_data&nonce=' + erdo_vars.nonce; 
        btn.text('Saving...').attr('disabled', 'disabled'); 
        
        $.post(erdo_vars.ajax_url, data, function(res) { 
            if(res.success) { 
                $('#erdo-modal-overlay').addClass('hidden');
                var d = res.data;
                var card = $('.erdo-card[data-id="' + d.id + '"]');
                card.removeClass('border-red border-orange border-green border-gray');
                var newStatus = d.status;
                if(d.is_used === false) newStatus = 'gray'; 
                card.addClass('border-' + newStatus);
                card.find('.erdo-card-info').text(d.filename);
                loadStats();
            } else { 
                alert('Error saving data!'); 
                btn.text('Save').removeAttr('disabled'); 
            } 
        }); 
    });

    $(document).on('click', '.webp-convert-btn', function() { 
        var id = $(this).data('id'); 
        $(this).text('Processing...').attr('disabled', 'disabled'); 
        // Düzenlendi: action: 'erdo_convert_webp'
        $.post(erdo_vars.ajax_url, { action: 'erdo_convert_webp', id: id, nonce: erdo_vars.nonce }, function(res) { 
            $('#erdo-modal-content').html(res);
            loadStats(); 
        }); 
    });

    $(document).on('click', '.erdo-delete-btn', function() { 
        if(!confirm('Permanently delete?')) return; 
        // Düzenlendi: action: 'erdo_delete_image'
        $.post(erdo_vars.ajax_url, { action: 'erdo_delete_image', id: $(this).data('id'), nonce: erdo_vars.nonce }, function() { location.reload(); }); 
    });

    $(document).on('click', '.erdo-magic-btn', function() { 
        var btn=$(this); 
        var t=btn.data('target'); 
        var s=btn.data('source'); 
        var v=''; 
        if(s==='parent'){v=$('#erdo-parent-title').val();} 
        else if(s==='filename'){v=$('#erdo-current-filename').val().replace(/\.[^/.]+$/,"").replace(/[-_]/g," ").replace(/\w\S*/g,function(txt){return txt.charAt(0).toUpperCase()+txt.substr(1).toLowerCase();});} 
        if(v){$('#erdo-input-'+t).val(v).css('background-color','#e5fdf4').animate({backgroundColor:'#fff'},500);} 
    });

    $(document).on('click', '.erdo-resize-btn', function(e) { 
        e.preventDefault(); 
        var btn=$(this); 
        var id=btn.data('id'); 
        var w=$('#erdo-w-'+id).val(); 
        var h=$('#erdo-h-'+id).val(); 
        if(!w||!h){alert('Enter size.');return;} 
        btn.text('...').attr('disabled','disabled'); 
        $.post(erdo_vars.ajax_url,{action:'erdo_manual_resize',id:id,w:w,h:h,nonce:erdo_vars.nonce},function(res){ 
            if(res.success){
                // Düzenlendi: action:'erdo_get_detail'
                $.post(erdo_vars.ajax_url,{action:'erdo_get_detail',id:id,nonce:erdo_vars.nonce},function(html){$('#erdo-modal-content').html(html);});
            } else{
                alert('Failed.');btn.text('Resize').removeAttr('disabled');
            } 
        }); 
    });

    $('#erdo-refresh-all-btn').on('click', function() { 
        if(!confirm('Refresh?')) return; 
        // Düzenlendi: action: 'erdo_refresh_all'
        $.post(erdo_vars.ajax_url, { action: 'erdo_refresh_all', nonce: erdo_vars.nonce }, function() { location.reload(); }); 
    });

    $('#erdo-upload-btn').on('click', function(e) { 
        e.preventDefault(); 
        var frame = wp.media({ title: 'Add New', button: { text: 'Add' }, multiple: true }); 
        frame.on('close', function() { location.reload(); }); 
        frame.open(); 
    });

    $('#erdo-close-btn, #erdo-modal-overlay').on('click', function(e) { 
        if (e.target === this) $('#erdo-modal-overlay').addClass('hidden'); 
    });
    $('#erdo-safety-cancel-btn').on('click', function() { $('#erdo-safety-modal-overlay').addClass('hidden'); });

    $('#erdo-settings-btn').on('click', function() { 
        $('#erdo-modal-overlay').removeClass('hidden'); 
        $('#erdo-modal-content').html('<p style="text-align:center; padding:50px;">Loading...</p>'); 
        // Düzenlendi: action: 'erdo_get_settings'
        $.post(erdo_vars.ajax_url, { action: 'erdo_get_settings', nonce: erdo_vars.nonce }, function(res) { $('#erdo-modal-content').html(res); }); 
    });

    $(document).on('click', '#erdo-save-settings-btn', function() { 
        var btn=$(this); 
        // Düzenlendi: action=erdo_save_settings
        var data=$('#erdo-settings-form').serialize()+'&action=erdo_save_settings&nonce='+erdo_vars.nonce; 
        btn.text('Saving...'); 
        $.post(erdo_vars.ajax_url, data, function(res){ 
            if(res.success){ btn.text('Saved!'); setTimeout(function(){$('#erdo-modal-overlay').addClass('hidden');},800); } 
        }); 
    });

    $('#erdo-scan-thumbnails-btn').on('click', function() { 
        $('#erdo-modal-overlay').removeClass('hidden'); 
        $('#erdo-modal-content').html('<div style="text-align:center; padding:40px;"><span class="dashicons dashicons-search" style="font-size:40px; width:40px; height:40px; color:#2271b1; animation: spin 2s linear infinite;"></span><h3>Scanning...</h3></div>'); 
        $.post(erdo_vars.ajax_url, { action: 'erdo_scan_thumbnails', nonce: erdo_vars.nonce }, function(res) { 
            if(res.success) { 
                var html = '<div class="modal-header"><h2>Missing Featured Images</h2></div>'; 
                if ( res.data.length === 0 ) { 
                    html += '<div style="padding:40px; text-align:center; color:#46b450;"><span class="dashicons dashicons-yes-alt" style="font-size:60px; width:60px; height:60px; margin-bottom:15px;"></span><h3>Good Job!</h3><p>All your posts have featured images.</p></div>'; 
                } else { 
                    html += '<p style="padding:0 20px; color:#d63638;">Found <strong>' + res.data.length + '</strong> posts without a featured image:</p>'; 
                    html += '<ul style="padding:20px; max-height:400px; overflow-y:auto;">'; 
                    $.each(res.data, function(i, item) { 
                        html += '<li style="background:#f6f7f7; border:1px solid #dcdcde; padding:10px; margin-bottom:10px; display:flex; justify-content:space-between; align-items:center; border-radius:4px;"><strong>' + item.title + '</strong><a href="' + item.link + '" target="_blank" class="button button-small">Edit Post <span class="dashicons dashicons-external" style="font-size:12px; line-height:24px;"></span></a></li>'; 
                    }); 
                    html += '</ul>'; 
                } 
                $('#erdo-modal-content').html(html); 
            } else {
                 $('#erdo-modal-content').html('<p style="padding:20px; color:red;">Error scanning posts.</p>');
            }
        }); 
    });
    
    $('#erdo-bulk-webp-btn').on('click', function() { 
        if(!confirm('Convert all eligible images to WebP? This might take a while.')) return; 
        var btn=$(this); 
        btn.attr('disabled','disabled').text('Scanning...'); 
        // Düzenlendi: action: 'erdo_get_bulk_list'
        $.post(erdo_vars.ajax_url, { action: 'erdo_get_bulk_list', nonce: erdo_vars.nonce }, function(res) { 
            if(res.success && res.data.length > 0) { runBulkProcess(res.data, 'erdo_bulk_step', btn); } 
            else { alert('No images found to convert.'); btn.removeAttr('disabled').text('Bulk Convert'); } 
        }); 
    });

    $('#erdo-bulk-delete-btn').on('click', function() { 
        $('#erdo-safety-modal-overlay').removeClass('hidden'); 
        $('#erdo-safety-input').val('').focus();
        $('#erdo-safety-confirm-btn').attr('disabled', 'disabled');
    });

    $('#erdo-safety-input').on('keyup', function() {
        if($(this).val() === 'DELETE') {
            $('#erdo-safety-confirm-btn').removeAttr('disabled');
        } else {
            $('#erdo-safety-confirm-btn').attr('disabled', 'disabled');
        }
    });

    $('#erdo-safety-confirm-btn').on('click', function() {
        $('#erdo-safety-modal-overlay').addClass('hidden');
        var btn = $('#erdo-bulk-delete-btn'); 
        btn.attr('disabled','disabled').text('Scanning...'); 
        // Düzenlendi: action: 'erdo_get_unused_list'
        $.post(erdo_vars.ajax_url, { action: 'erdo_get_unused_list', nonce: erdo_vars.nonce }, function(res) { 
            // Düzenlendi: erdo_delete_image
            if(res.success && res.data.length > 0) { runBulkProcess(res.data, 'erdo_delete_image', btn); } 
            else { alert('No unused images found.'); btn.removeAttr('disabled').text('Delete All Unused'); } 
        }); 
    });

    function runBulkProcess(ids, action, btn) { 
        var total=ids.length; 
        var current=0; 
        $('#erdo-bulk-progress-wrapper').removeClass('hidden'); 
        $('#erdo-progress-total').text(total); 
        function processNext() { 
            if(ids.length===0){ btn.text('Completed!'); setTimeout(function(){location.reload();},1000); return;} 
            var currentId=ids.shift(); 
            current++; 
            $('#erdo-progress-current').text(current); 
            $('#erdo-progress-fill').css('width', (current/total)*100+'%'); 
            $.post(erdo_vars.ajax_url, { action:action, id:currentId, nonce:erdo_vars.nonce }, function(){ processNext(); }).fail(function(){ processNext(); }); 
        } 
        processNext(); 
    }
});