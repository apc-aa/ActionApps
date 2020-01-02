function buildSearchesList(searches, searchesList, form, container, text) {
    searchesList.empty();

    if (searches.length) {
        searchesList.append( $('<div class=aa-form-saver__lbl>'+text+'</div>') );
    }

    var btn_counter=0;

    // Loops over each previous search to create a list shown to the user.
    // Each item of the list shown will display as a mnemonic name the
    // value of the main field, and a sublist containing all the fields
    // of the form displayed only when the user hovers or focuses on
    // the mnemonic name.
    // Every time the user clicks, or press ENTER or SPACE on an item
    // of the list, the form is autofilled using the selected set of values.
    for (var i = 0; i < searches.length; i++) {
        // Converts each item in the list retrieved from the local storage
        // into a JSON-parsable string and then convert it into its
        // equivalent object.
        var params = JSON.parse('{"' +
            decodeURIComponent(
                searches[i]
                    .replace(/&/g, '","')
                    .replace(/=/g, '":"')
                    .replace(/\+/g, ' ')
            ) +
            '"}'
        );

        // Just find the first value
        var firstinput;
        for (firstinput in params) {
            break;
        }

        // Adds the sublist to the main list and add the event handler
        // for the click and the keypress events
        (function(searchData) {
            var btn_text = params[firstinput];
            if (btn_text.length == 0) {
                btn_text = '-'+(++btn_counter)+'-';
            } else if (btn_text.length > 16) {
                btn_text = btn_text.substring(0,16)+'…';
            }
            searchesList.append(
                $('<span tabindex=0 class=aa-form-saver__btn>')
                    .text(btn_text)
                    .on('click keypress', function(event) {
                        if ( event.type !== 'keypress' || event.keyCode === 13 || event.keyCode === 32 ) {
                            if (container == '') {
                                form.trigger('reset');
                            } else {
                                // reset the sub form
                                $(container+' input:checkbox').prop( "checked", false );
                                $(container+' select:selected').prop( "selected", false );
                            }
                            form.deserialize(searchData);
                        }
                    })
            );
        })(searches[i]);
    }
}

function FormSaver(search_id, container, text) {
    var searchesList = $('#aa-fs-' + search_id);
    var form = $((container == '') ? ('#aa-fs-' + search_id) : container).closest('form');
    var searches = window.localStorage.getItem('aafs_' + search_id);

    searches = (searches === null) ? [] : JSON.parse(searches);
    buildSearchesList(searches, searchesList, form, container, text);

    form.submit(function (event) {
        // The last performed search is at the top of the list.
        // Besides, the demo avoid storing the same
        // search multiple times, so the code searches for duplicates and
        // removes them. Finally, the demo stores a maximum of 10 searches per user.
        if (container=='') {
            var currentSearch = form.serialize();
        } else {
            var currentSearch = $(container+' input, '+container+' select, '+container+' textarea').serialize();
        }
        searches.unshift(currentSearch);
        // Removes the duplicates
        for (var i = 1; i < searches.length; i++) {
            if (searches[0] === searches[i]) {
                searches.splice(i, 1);
            }
        }

        // Stores only the last 5 searches
        if (i === searches.length && searches.length > 5) {
            searches.pop();
        }

        // Stores the new list into the local storage
        window.localStorage.setItem('aafs_' + search_id, JSON.stringify(searches));

        buildSearchesList(searches, searchesList, form, container, text);
    });
}