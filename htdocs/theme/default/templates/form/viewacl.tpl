<input type="hidden" name="accesslist" value="">
<div id="viewacl_lhs">
    <div id="potentialpresetitems"></div>
    <div>
        {{str tag=search}} <input type="text" name="search" id="search">
        <select name="type" id="type">
            <option value="group">{{str tag=groups}}</option>
            <option value="user" selected="selected">{{str tag=users}}</option>
        </select>
        <button id="dosearch" type="button">{{str tag=go}}</button>
        <table id="results">
            <thead>
                <tr>
                    <th></th>
                    <th>{{str tag=name}}</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            </tbody>
        </table>
    </div>
</div>
<h3>{{str tag=Added section=view}}</h3>
<div id="accesslistitems">
</div>

<script type="text/javascript">
var count = 0;

// Utility functions

// Given a row, render it on the left hand side
function renderPotentialPresetItem(item) {
    var addButton = BUTTON({'type': 'button'}, '{{str tag=add}}');
    var row = DIV(null, addButton, ' ', item.name);
    item.preset = true;

    connect(addButton, 'onclick', function() {
        appendChildNodes('accesslist', renderAccessListItem(item));
    });
    appendChildNodes('potentialpresetitems', row);

    return row;
}

// Given a row, render it on the right hand side
function renderAccessListItem(item) {
    var removeButton = BUTTON({'type': 'button'}, '{{str tag=remove}}');
    var dateInfo = TABLE(null,
        TBODY(null,
            TR(null,
                TH(null, get_string('From') + ':'),
                TD(null, makeCalendarInput(item, 'start'), makeCalendarLink(item, 'start'))
            ),
            TR(null,
                TH(null, get_string('To') + ':'),
                TD(null, makeCalendarInput(item, 'stop'), makeCalendarLink(item, 'stop'))
            )
        )
    );
    var cssClass = 'ai-container';
    if (item.preset) {
        cssClass += '  preset';
    }
    cssClass += ' ' + item.type + '-container';
    var name = item.name;
    if (item.type == 'user') {
        name = [IMG({'src': config.wwwroot + 'thumb.php?type=profileicon&id=' + item.id + '&maxwidth=20&maxheight=20'}), ' ', name];
    }
    var row = TABLE({'class': cssClass},
        TBODY(null, 
            TR(null,
                TH(null, name,  (item.tutoronly ? ' ' + '{{str tag=tutors section=view}}' : '')),
                TD({'class': 'right'}, removeButton)
            ),
            TR(null,
                TD({'colspan': 2},
                    dateInfo,
                    INPUT({
                        'type': 'hidden',
                        'name': 'accesslist[' + count + '][type]',
                        'value': item.type
                    }),
                    (item.id ?
                    INPUT({
                        'type': 'hidden',
                        'name': 'accesslist[' + count + '][id]',
                        'value': item.id
                    })
                    :
                    null
                    ),
                    (typeof(item.tutoronly) != 'undefined' ?
                    INPUT({
                        'type': 'hidden',
                        'name': 'accesslist[' + count + '][tutoronly]',
                        'value': item.tutoronly
                    })
                    :
                    null
                    )
                )
            )
        )
    );

    connect(removeButton, 'onclick', function() {
        removeElement(row);
    });
    appendChildNodes('accesslistitems', row);
    
    setupCalendar(item, 'start');
    setupCalendar(item, 'stop');
    count++;
}

function makeCalendarInput(item, type) {
    return INPUT({
        'type':'text',
        'name': 'accesslist[' + count + '][' + type + 'date]',
        'id'  :  type + 'date_' + count,
        'value': item[type + 'date'] ? item[type + 'date'] : '',
        'size': '15'
    });
}

function makeCalendarLink(item, type) {
    var link = A({
        'href'   : '',
        'id'     : type + 'date_' + count + '_btn',
        'onclick': 'return false;', // @todo do with mochikit connect
        'class'  : 'pieform-calendar-toggle'},
        IMG({
            'src': '{{theme_path location='images/calendar.gif'}}',
            'alt': ''})
    );
    return link;
}

function setupCalendar(item, type) {
    //log(type);
    var dateStatusFunc, selectedFunc;
    //if (type == 'start') {
    //    dateStatusFunc = function(date) {
    //        startDateDisallowed(date, $(item.id + '_stopdate'));
    //    };
    //    selectedFunc = function(calendar, date) {
    //        startSelected(calendar, date, $(item.id + '_startdate'), $(item.id + '_stopdate'));
    //    }
    //}
    //else {
    //    dateStatusFunc = function(date) {
    //        stopDateDisallowed(date, $(item.id + '_startdate'));
    //    };
    //    selectedFunc = function(calendar, date) {
    //        stopSelected(calendar, date, $(item.id + '_startdate'), $(item.id + '_stopdate'));
    //    }
    //}
    if (!$(type + 'date_' + count)) {
        logWarn('Couldn\'t find element: ' + type + 'date_' + count);
        return;
    }
    Calendar.setup({
        "ifFormat"  :"%Y\/%m\/%d %H:%M",
        "daFormat"  :"%Y\/%m\/%d %H:%M",
        "inputField": type + 'date_' + count,
        "button"    : type + 'date_' + count + '_btn',
        //"dateStatusFunc" : dateStatusFunc,
        //"onSelect"       : selectedFunc
        "showsTime" : true
    });
}

// SETUP

// Left top: public, loggedin, friends
var potentialPresets = {{$potentialpresets}};
forEach(potentialPresets, function(preset) {
    renderPotentialPresetItem(preset);
});

// Left hand side
var searchTable = new TableRenderer(
    'results',
    'access.json.php',
    [
        undefined, undefined, undefined
    ]
);
searchTable.statevars.push('type');
searchTable.statevars.push('query');
searchTable.type = 'user';
searchTable.pagerOptions = {
    'firstPageString': '\u00AB',
    'previousPageString': '<',
    'nextPageString': '>',
    'lastPageString': '\u00BB',
    'linkOptions': {
        'href': '',
        'style': 'padding-left: 0.5ex; padding-right: 0.5ex;'
    }
}
searchTable.query = '';
searchTable.rowfunction = function(rowdata, rownumber, globaldata) {
    rowdata.type = searchTable.type;
    var buttonTD = TD({'style': 'white-space:nowrap;'});

    var addButton = BUTTON({'type': 'button', 'class': 'button'}, '{{str tag=add}}');
    connect(addButton, 'onclick', function() {
        rowdata.tutoronly = 0;
        appendChildNodes('accesslist', renderAccessListItem(rowdata));
    });
    appendChildNodes(buttonTD, addButton);

    var identityNodes = [], profileIcon = null, tutorAddButton = null;
    if (rowdata.type == 'user') {
        profileIcon = IMG({'src': config.wwwroot + 'thumb.php?type=profileicon&maxwidth=40&maxheight=40&id=' + rowdata.id});
        identityNodes.push(A({'href': config.wwwroot + 'user/view.php?id=' + rowdata.id, 'target': '_blank'}, rowdata.name));
    }
    else if (rowdata.type == 'group') {
        if (rowdata.jointype == 'controlled') {
            tutorAddButton = BUTTON({'type': 'button', 'class': 'button'}, '{{str tag=addtutors section=view}}');
            connect(tutorAddButton, 'onclick', function() {
                rowdata.tutoronly = 1;
                appendChildNodes('accesslist', renderAccessListItem(rowdata));
            });
            appendChildNodes(buttonTD, tutorAddButton);
        }
        identityNodes.push(A({'href': config.wwwroot + 'group/view.php?id=' + rowdata.id, 'target': '_blank'}, rowdata.name));
    }

    return TR({'class': 'r' + (rownumber % 2)},
        buttonTD,
        TD({'style': 'vertical-align: middle;'}, identityNodes),
        TD({'class': 'center', 'style': 'width:40px'}, profileIcon)
    );
}
searchTable.updateOnLoad();

function search(e) {
    searchTable.query = $('search').value;
    searchTable.type  = $('type').options[$('type').selectedIndex].value;
    searchTable.doupdate();
    e.stop();
}


// Right hand side
addLoadEvent(function () {
    var accesslist = {{$accesslist}};
    if (accesslist) {
        forEach(accesslist, function(item) {
            renderAccessListItem(item);
        });
    }
});

addLoadEvent(function() {
    // Populate the "potential access" things (public|loggedin|allfreidns)

    connect($('search'), 'onkeydown', function(e) {
        if (e.key().string == 'KEY_ENTER') {
            search(e);
        }
    });
    connect($('dosearch'), 'onclick', search);
});

</script>