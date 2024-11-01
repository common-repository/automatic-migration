jQuery(function($) {
    $('.js-hide').hide();
    $('.js-show').show();

    var dataSuccess = function(data) {
        if(data == -1) {
            return;
        }

        $('#server-response-loading').hide();

        $(data).each(function(index, message) {
            var li = $('<li></li>').text(message);
            $('#server-response-list').append(li);
        });

        migrateData();
    };

    var migrateData = function() {
        $.ajax({
            data: {
                action: 'migrate_data'
            },
            dataType: 'json',
            type: 'POST',
            url: ajaxurl,
            success: dataSuccess
        });
    };

    var initError = function() {
        $('#server-response-loading').hide();

        var li = $('<li></li>').text("Couldn't contact new blog - URL might be wrong or some files may not have copied.");
        $('#server-response-list').append(li);
    };

    var filesSuccess = function(data) {
        // Plugin returns a negative one, there are no files left
        if(data == -1) {
            $.ajax({
                data: {
                action: 'migrate_init'
                },
                type: 'POST',
                url: ajaxurl,
                success: migrateData,
                error: initError
            });
            return;
        }

        // Only tell us when files were actually copied
        if(data) {
            var li = $('<li></li>').text(data);
            $('#server-response-list').append(li);
        }

        transfer();
    };

    var transfer = function() {
        $.ajax({
            data: {
                action: 'migrate_files'
            },
            dataType: 'json',
            type: 'POST',
            url: ajaxurl,
            success: filesSuccess
        });
    };

    // TODO: Find a better condition to start transfer after proper info is collected
    if($('#server-response-list').length) {
        transfer();
    }
});
