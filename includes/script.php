<script>
var expandCode = function() {
    document.querySelector('body').classList.add('code_expanded');
    return false;
};

var shrinkCode = function() {
    document.querySelector('body').classList.remove('code_expanded');
    return false;
};
    
var toggleSettings = function() {
    document.querySelector('#settings_panel').classList.toggle('hidden');
    document.querySelector('#psalm_output').classList.toggle('hidden');
    return false;
};
    
var settingsText = {
    'unused_variables': 'Detect unused variables and parameters',
    'unused_methods': 'Detect unused classes and methods',
    'memoize_properties': 'Memoize property assignments',
    'memoize_method_calls': 'Memoize simple method calls',
    'check_throws': 'Check for <code>@throws</code> docblock',
    'strict_internal_functions': 'Use strict internal function results',
    'allow_phpstorm_generics': 'Allow PHPStorm generic annotations (e.g. Traversable|string[])',
};
    
var toggleSetting = function(key) {
    if (key in settings) {
        settings[key] = !settings[key];
    } else {
        settings[key] = true;
    }

    editor.performLint();
    
    return false;
};

var redrawSettings = function() {
    var settingsLines = [];

    Object.keys(settingsText).forEach(function (key) {
        var checked = key in settings && settings[key];
        var clickHandler = 'javascript:toggleSetting(\'' + key + '\')';
        var input = '<input id="' + key + '" type="checkbox" onclick="' + clickHandler + '"' + (checked ? ' checked' : '') + '>';
        
        settingsLines.push(
            '<div>' + input + ' <label for="' + key + '">' + settingsText[key] + '</label></div>'
        );
    });
    document.getElementById('settings_panel').innerHTML = settingsLines.join('\n');
};

var getLink = function() {
    fetch('/add_code', {
        method: 'POST',
        headers: {
            'Accept': 'application/json, application/xml, text/plain, text/html, *.*',
            'Content-Type': 'application/x-www-form-urlencoded; charset=utf-8'
        },
        body: serializeJSON({code: editor.getValue(), settings: JSON.stringify(settings)})
    })
    .then(function (response) {
        return response.text();
    })
    .then(function (response) {
        if (response.indexOf('/r/') === -1) {
            alert(response);
        } else {
            window.location = '//' + response;
        }
    });
    return false;
};

var serializeJSON = function(data) {
    return Object.keys(data).map(function (keyName) {
        return encodeURIComponent(keyName) + '=' + encodeURIComponent(data[keyName])
    }).join('&');
}

var urlParams = new URLSearchParams(window.location.search);

var latestFetch = 0;

var fetchAnnotations = function (code, callback, options, cm) {
    latestFetch++;
    fetchKey = latestFetch;

    var submitData = {
        code: code,
        settings: JSON.stringify(settings)
    };

    if (urlParams.has('php')) {
        submitData.php = urlParams.get('php');
    }

    fetch('/check', {
        method: 'POST',
        headers: {
            'Accept': 'application/json, application/xml, text/plain, text/html, *.*',
            'Content-Type': 'application/x-www-form-urlencoded; charset=utf-8'
        },
        body: serializeJSON(submitData)
    })
    .then(function (response) {
        return response.json();
    })
    .then(function (response) {
        if (latestFetch != fetchKey) {
            return;
        }

        if ('results' in response) {
            var psalm_version = response.version;
            
            if (psalm_version.indexOf('@')) {
                psalm_version = psalm_version.split('@')[1];
            }

            var psalm_header = 'Psalm output (using commit ' + psalm_version.substring(0, 7) + '): <br><br>'

            if (response.results.length === 0) {
                document.getElementById('psalm_output').innerHTML = psalm_header + 'No issues!';

                callback(
                    response.type_map.map(
                        function (type_data) {
                            return {
                                severity: 'type',
                                message: type_data.type,
                                from: cm.posFromIndex(type_data.from),
                                to: cm.posFromIndex(type_data.to)
                            };
                        }
                    )
                );
            }
            else {
                var text = response.results.map(
                    function (issue) {
                        return (issue.severity === 'error' ? 'ERROR' : 'INFO') + ': '
                            + '<a href="' + issue.link + '">' + issue.type + '</a> - ' + issue.line_from + ':'
                            + issue.column_from + ' - ' + issue.message.replace(/[\u00A0-\u9999<>\&]/gim, function(i) {
   return '&#'+i.charCodeAt(0)+';';
});
                    }
                );

                document.getElementById('psalm_output').innerHTML = psalm_header + text.join('<br><br>') + '<br>&nbsp;';

                callback(
                    response.results.map(
                        function (issue) {
                            return {
                                severity: issue.severity === 'error' ? 'error' : 'warning',
                                message: issue.message,
                                from: cm.posFromIndex(issue.from),
                                to: cm.posFromIndex(issue.to)
                            };
                        }
                    ).concat(
                        response.type_map.map(
                            function (type_data) {
                                return {
                                    severity: 'type',
                                    message: type_data.type,
                                    from: cm.posFromIndex(type_data.from),
                                    to: cm.posFromIndex(type_data.to)
                                };
                            }
                        )
                    )
                );
            }

            if ('fixable_errors' in response && response.fixable_errors > 0) {
                document.getElementById('psalm_output').innerHTML = 'Psalm detected ' + response.fixable_errors + ' fixable errors<br>&nbsp;';

                const textarea = cm.getTextArea()
                const container = textarea.parentNode;

                fix_button = document.createElement('button');
                fix_button.innerText = 'Fix code';
                container.appendChild(fix_button);

                fix_button.addEventListener(
                    'click',
                    function() {
                        fetchFixedContents(cm.getValue(), cm);
                    }
                );
            }
        }
        else if ('error' in response) {
            var error_type = response.error.type === 'parser_error' ? 'Parser' : 'Internal Psalm';
            document.getElementById('psalm_output').innerText = 'Psalm runner output: \n\n'
                + error_type + ' error on line ' + response.error.line_from + ' - '
                + response.error.message;

            console.log(cm.posFromIndex(response.error.to));

            callback({
               message: response.error.message,
               severity: 'error',
               from: cm.posFromIndex(response.error.from),
               to: cm.posFromIndex(response.error.to),
            });
        }
    })
    .catch (function (error) {
        console.log('Request failed', error);
    });
};

var editor = CodeMirror.fromTextArea(document.getElementById("code"), {
    lineNumbers: true,
    matchBrackets: true,
    lineSeparator: "\n",
    mode: 'application/x-httpd-php',
    inputStyle: 'contenteditable',
    indentWithTabs: false,
    indentUnit: 4,
    theme: 'elegant',
    lint: {
        getAnnotations: fetchAnnotations,
        async: true,
    }
});

//editor.focus();
editor.setCursor(editor.lineCount(), 0);
    
redrawSettings();

</script>
