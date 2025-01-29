jQuery(document).ready(function($) {
    function initializeDelete(type) {
        // Show spinner, disable button
        $(`#delete-${type}s-btn .poc-spinner`).show();
        $(`#delete-${type}s-btn`).prop('disabled', true);
        
        $.ajax({
            url: poc_ajax_obj.ajax_url,
            type: 'POST',
            data: {
                action: 'poc_get_counts',
                nonce: poc_ajax_obj.nonce
            },
            success: function(response) {
                if (response.success) {
                    const count = type === 'product' ? response.data.product_count : response.data.order_count;
                    if (count === 0) {
                        alert(`No ${type}s found to delete.`);
                        location.reload();
                        return;
                    }
                    $(`#${type}-total`).text(count);
                    $(`#${type}-progress-container`).slideDown(300);
                    processBatch(type, 0, count);
                } else {
                    handleError(type, response.data || 'Error getting counts');
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                handleError(type, `Failed to initialize: ${errorThrown}`);
            }
        });
    }

    function processBatch(type, offset, total) {
        $.ajax({
            url: poc_ajax_obj.ajax_url,
            type: 'POST',
            data: {
                action: `poc_delete_${type}s_batch`,
                nonce: poc_ajax_obj.nonce,
                offset: offset
            },
            success: function(response) {
                if (response.success) {
                    const processed = offset + response.data.deleted;
                    const percentage = Math.round((processed / total) * 100);
                    
                    // Update progress
                    $(`#${type}-progress-bar`).css('width', percentage + '%');
                    $(`#${type}-processed`).text(processed);
                    
                    // Log any errors from this batch
                    if (response.data.errors && response.data.errors.length > 0) {
                        console.error(`Errors during ${type} deletion:`, response.data.errors);
                    }
                    
                    if (!response.data.done) {
                        // Process next batch
                        processBatch(type, processed, total);
                    } else {
                        // Verify deletion
                        verifyDeletion(type);
                    }
                } else {
                    handleError(type, response.data || `Error deleting ${type}s`);
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                handleError(type, `Failed to process batch: ${errorThrown}`);
            }
        });
    }

    function verifyDeletion(type) {
        $.ajax({
            url: poc_ajax_obj.ajax_url,
            type: 'POST',
            data: {
                action: 'poc_get_counts',
                nonce: poc_ajax_obj.nonce
            },
            success: function(response) {
                if (response.success) {
                    const remainingCount = type === 'product' ? response.data.product_count : response.data.order_count;
                    if (remainingCount > 0) {
                        handleError(type, `Some ${type}s could not be deleted. Please try again.`);
                    } else {
                        $(`#delete-${type}s-btn .poc-spinner`).hide();
                        alert(`All ${type}s have been successfully deleted!`);
                        location.reload();
                    }
                } else {
                    handleError(type, `Error verifying ${type} deletion`);
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                handleError(type, `Failed to verify deletion: ${errorThrown}`);
            }
        });
    }

    function handleError(type, errorMessage) {
        console.error(errorMessage);
        $(`#delete-${type}s-btn .poc-spinner`).hide();
        $(`#delete-${type}s-btn`).prop('disabled', false);
        alert(`Error: ${errorMessage}`);
        location.reload();
    }

    $('#delete-products-btn').click(function() {
        if (confirm('Are you absolutely sure you want to delete ALL products? This cannot be undone!')) {
            initializeDelete('product');
        }
    });

    $('#delete-orders-btn').click(function() {
        if (confirm('Are you absolutely sure you want to delete ALL orders? This cannot be undone!')) {
            initializeDelete('order');
        }
    });
});