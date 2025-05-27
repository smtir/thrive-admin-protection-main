jQuery(document).ready(function ($) {
    function showToast(message, type = 'success') {
        Toastify({
            text: message,
            duration: 3000,
            close: true,
            gravity: 'top',
            position: 'right',
            backgroundColor: type === 'success' ? "#4CAF50" : "#f44336",
        }).showToast();
    }

    function runAction(button, slug, type, actionType) {
        button.prop('disabled', true).text('Processing...');

        $.post(ThrivePluginAjax.ajax_url, {
            action: type === 'plugin' ? 'thrive_force_plugin_action' : 'thrive_force_theme_action',
            slug: slug,
            action_type: actionType,
            nonce: ThrivePluginAjax.nonce
        }).done(function (res) {
            showToast(res.data.message, 'success');
            location.reload();
        }).fail(function (xhr) {
            showToast(xhr.responseJSON?.data?.message || 'Something went wrong.', 'error');
            button.prop('disabled', false).text(actionType.charAt(0).toUpperCase() + actionType.slice(1));
        });
    }

    $(document).on('click', '.thrive-plugin-action', function () {
        const button = $(this);
        const slug = button.data('slug');
        const actionType = button.data('action');
        runAction(button, slug, 'plugin', actionType);
    });

    $(document).on('click', '.thrive-theme-action', function () {
        const button = $(this);
        const slug = button.data('slug');
        const actionType = button.data('action');
        runAction(button, slug, 'theme', actionType);
    });

    $('#thrive-bulk-install').click(function () {
        $('.thrive-plugin-action[data-action="install"]').each(function () {
            $(this).trigger('click');
        });
    });

    $('#thrive-bulk-activate').click(function () {
        $('.thrive-plugin-action[data-action="activate"]').each(function () {
            $(this).trigger('click');
        });
    });
});