(function (window, document, $) {
    'use strict';

    if (!$) {
        return;
    }

    var preparedData = null;
    var dataRequest = null;
    var controls = [];
    var menuSequence = 0;

    function normalize(value) {
        var normalized = $.trim(value || '').toLowerCase();

        if (normalized.normalize) {
            normalized = normalized.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
        }

        return normalized.replace(/[^a-z0-9]+/g, ' ').replace(/^\s+|\s+$/g, '');
    }

    function scoreMatch(value, query) {
        var position;

        if (!query) {
            return 0;
        }

        if (value === query) {
            return 0;
        }

        if (value.indexOf(query) === 0) {
            return 1;
        }

        if (value.indexOf(' ' + query) !== -1) {
            return 2;
        }

        position = value.indexOf(query);
        return position === -1 ? -1 : 3 + position;
    }

    function formatDate(value) {
        var parts = String(value || '').split('-');
        return parts.length === 3 ? parts[2] + '/' + parts[1] + '/' + parts[0] : value;
    }

    function prepare(raw) {
        var provinceByValue = {};
        var municipalityByName = {};
        var capByValue = {};
        var provinces = [];
        var municipalities = [];

        $.each(raw.p || [], function (_, row) {
            var province = {
                code: $.trim(row.c || '').toUpperCase(),
                name: $.trim(row.n || ''),
                historical: false
            };

            province.searchCode = normalize(province.code);
            province.searchName = normalize(province.name);
            provinces.push(province);
            provinceByValue[province.searchCode] = province;
            provinceByValue[province.searchName] = province;
        });

        $.each(raw.m || [], function (_, row) {
            var municipality = {
                name: $.trim(row.n || ''),
                province: $.trim(row.p || '').toUpperCase(),
                caps: row.c || [],
                historical: false,
                validTo: ''
            };

            municipality.searchName = normalize(municipality.name);
            municipalities.push(municipality);

            if (!municipalityByName[municipality.searchName]) {
                municipalityByName[municipality.searchName] = [];
            }
            municipalityByName[municipality.searchName].push(municipality);

            $.each(municipality.caps, function (_, capValue) {
                var cap = $.trim(capValue || '');

                if (!capByValue[cap]) {
                    capByValue[cap] = {
                        value: cap,
                        municipalities: []
                    };
                }
                capByValue[cap].municipalities.push(municipality);
            });
        });

        $.each(raw.h || [], function (_, row) {
            var municipality = {
                name: $.trim(row.n || ''),
                province: $.trim(row.p || '').toUpperCase(),
                caps: [],
                historical: true,
                validTo: $.trim(row.u || '')
            };

            municipality.searchName = normalize(municipality.name);
            municipalities.push(municipality);

            if (!municipalityByName[municipality.searchName]) {
                municipalityByName[municipality.searchName] = [];
            }
            municipalityByName[municipality.searchName].push(municipality);

            if (municipality.province && !provinceByValue[normalize(municipality.province)]) {
                var historicalProvince = {
                    code: municipality.province,
                    name: 'Provincia storica',
                    historical: true,
                    searchCode: normalize(municipality.province),
                    searchName: 'provincia storica'
                };
                provinces.push(historicalProvince);
                provinceByValue[historicalProvince.searchCode] = historicalProvince;
            }
        });

        return {
            updated: raw.updated || '',
            provinces: provinces,
            provinceByValue: provinceByValue,
            municipalities: municipalities,
            municipalityByName: municipalityByName,
            caps: $.map(capByValue, function (cap) { return cap; }),
            capByValue: capByValue
        };
    }

    function loadData(url) {
        if (preparedData) {
            return $.Deferred().resolve(preparedData).promise();
        }

        if (!dataRequest) {
            dataRequest = $.getJSON(url).then(function (raw) {
                preparedData = prepare(raw || {});
                return preparedData;
            }, function () {
                dataRequest = null;
                return $.Deferred().reject().promise();
            });
        }

        return dataRequest;
    }

    function exactProvinceCode(value) {
        var province = preparedData && preparedData.provinceByValue[normalize(value)];
        return province ? province.code : '';
    }

    function exactMunicipalities(value, provinceCode) {
        var matches = preparedData && preparedData.municipalityByName[normalize(value)];

        matches = matches || [];
        if (provinceCode) {
            matches = $.grep(matches, function (item) {
                return item.province === provinceCode;
            });
        }

        return matches;
    }

    function exactCap(value) {
        var cap = $.trim(value || '');
        return preparedData && /^\d{5}$/.test(cap) ? preparedData.capByValue[cap] : null;
    }

    function municipalityFitsContext(item, group) {
        var provinceCode = exactProvinceCode(group.$province.val());
        var cap = exactCap(group.$postalCode.val());

        if (provinceCode && item.province !== provinceCode) {
            return false;
        }

        if (cap && $.inArray(item, cap.municipalities) === -1) {
            return false;
        }

        return true;
    }

    function municipalityResults(query, group) {
        var results = [];
        var contextual = [];

        $.each(preparedData.municipalities, function (_, item) {
            var score = scoreMatch(item.searchName, query);

            if (score < 0) {
                return;
            }

            var result = {
                type: 'municipality',
                value: item.name,
                title: item.name + (item.province ? ' (' + item.province + ')' : ''),
                detail: item.historical
                    ? 'Comune storico' + (item.validTo ? ' fino al ' + formatDate(item.validTo) : '')
                    : (item.caps.length ? 'CAP ' + item.caps.join(', ') : 'CAP non disponibile'),
                item: item,
                score: score + (item.historical ? 20 : 0)
            };

            results.push(result);
            if (municipalityFitsContext(item, group)) {
                contextual.push(result);
            }
        });

        if (contextual.length) {
            results = contextual;
        }

        results.sort(function (a, b) {
            return a.score - b.score || a.title.localeCompare(b.title);
        });

        return results.slice(0, 12);
    }

    function provinceContextCodes(group) {
        var codes = {};
        var cap = exactCap(group.$postalCode.val());
        var municipalities = exactMunicipalities(group.$municipality.val(), '');

        $.each(municipalities, function (_, item) {
            if (item.province) {
                codes[item.province] = true;
            }
        });

        if (cap) {
            $.each(cap.municipalities, function (_, item) {
                if (item.province) {
                    codes[item.province] = true;
                }
            });
        }

        return codes;
    }

    function provinceResults(query, group) {
        var results = [];
        var contextual = [];
        var contextCodes = provinceContextCodes(group);
        var hasContext = !$.isEmptyObject(contextCodes);

        $.each(preparedData.provinces, function (_, item) {
            var scoreCode = scoreMatch(item.searchCode, query);
            var scoreName = scoreMatch(item.searchName, query);
            var score;

            if (scoreCode < 0 && scoreName < 0) {
                return;
            }

            score = scoreCode < 0 ? scoreName : (scoreName < 0 ? scoreCode : Math.min(scoreCode, scoreName));
            var result = {
                type: 'province',
                value: item.code,
                title: item.code + ' — ' + item.name,
                detail: item.historical ? 'Sigla storica' : 'Provincia',
                item: item,
                score: score
            };

            results.push(result);
            if (contextCodes[item.code]) {
                contextual.push(result);
            }
        });

        if (hasContext && contextual.length) {
            results = contextual;
        }

        results.sort(function (a, b) {
            return a.score - b.score || a.title.localeCompare(b.title);
        });

        return results.slice(0, 12);
    }

    function capFitsContext(cap, group) {
        var provinceCode = exactProvinceCode(group.$province.val());
        var municipalities = exactMunicipalities(group.$municipality.val(), provinceCode);
        var municipalityNames = {};
        var matchesProvince = !provinceCode;
        var matchesMunicipality = !municipalities.length;

        $.each(municipalities, function (_, item) {
            municipalityNames[item.searchName + '|' + item.province] = true;
        });

        $.each(cap.municipalities, function (_, item) {
            if (item.province === provinceCode) {
                matchesProvince = true;
            }
            if (municipalityNames[item.searchName + '|' + item.province]) {
                matchesMunicipality = true;
            }
        });

        return matchesProvince && matchesMunicipality;
    }

    function capDetail(cap) {
        var labels = [];

        $.each(cap.municipalities, function (_, item) {
            var label = item.name + (item.province ? ' (' + item.province + ')' : '');
            if ($.inArray(label, labels) === -1) {
                labels.push(label);
            }
        });

        labels.sort();
        if (labels.length > 3) {
            return labels.slice(0, 3).join(', ') + ' e altri ' + (labels.length - 3);
        }

        return labels.join(', ');
    }

    function postalCodeResults(query, group) {
        var results = [];
        var contextual = [];

        $.each(preparedData.caps, function (_, cap) {
            var score = scoreMatch(cap.value, query);

            if (score < 0) {
                return;
            }

            var result = {
                type: 'postalCode',
                value: cap.value,
                title: cap.value,
                detail: capDetail(cap),
                item: cap,
                score: score
            };

            results.push(result);
            if (capFitsContext(cap, group)) {
                contextual.push(result);
            }
        });

        if (contextual.length) {
            results = contextual;
        }

        results.sort(function (a, b) {
            return a.score - b.score || a.value.localeCompare(b.value);
        });

        return results.slice(0, 12);
    }

    function selectResult(control, result) {
        control.$input.val(result.value).trigger('change');
        hideMenu(control);
    }

    function setActive(control, index) {
        var $items = control.$menu.find('.italian-address-autocomplete-item');

        if (!$items.length) {
            control.activeIndex = -1;
            return;
        }

        if (index < 0) {
            index = $items.length - 1;
        } else if (index >= $items.length) {
            index = 0;
        }

        control.activeIndex = index;
        $items.removeClass('is-active').attr('aria-selected', 'false');
        $items.eq(index).addClass('is-active').attr('aria-selected', 'true');
    }

    function hideMenu(control) {
        control.results = [];
        control.activeIndex = -1;
        control.$menu.empty().hide();
        control.$input.attr('aria-expanded', 'false');
    }

    function showMessage(control, message) {
        control.results = [];
        control.activeIndex = -1;
        control.$menu.empty().append(
            $('<div class="italian-address-autocomplete-message"></div>').text(message)
        ).show();
        control.$input.attr('aria-expanded', 'true');
    }

    function renderResults(control, results) {
        control.results = results;
        control.activeIndex = -1;
        control.$menu.empty();

        if (!results.length) {
            showMessage(control, 'Nessun suggerimento. Puoi comunque inserire il valore manualmente.');
            return;
        }

        $.each(results, function (index, result) {
            var $button = $('<button type="button" class="italian-address-autocomplete-item" role="option" aria-selected="false"></button>');
            $button.attr('data-index', index);
            $button.append($('<span class="italian-address-autocomplete-title"></span>').text(result.title));
            if (result.detail) {
                $button.append($('<span class="italian-address-autocomplete-detail"></span>').text(result.detail));
            }
            control.$menu.append($button);
        });

        control.$menu.show();
        control.$input.attr('aria-expanded', 'true');
    }

    function runSearch(control) {
        var rawQuery = $.trim(control.$input.val() || '');
        var query = normalize(rawQuery);
        var minimumLength = control.type === 'province' ? 1 : 2;

        if (rawQuery.length < minimumLength) {
            hideMenu(control);
            return;
        }

        if (control.type === 'postalCode' && !/^\d+$/.test(rawQuery)) {
            hideMenu(control);
            return;
        }

        if (!preparedData) {
            showMessage(control, 'Caricamento suggerimenti...');
        }

        loadData(control.dataUrl).done(function () {
            var results;

            if (!control.isFocused || $.trim(control.$input.val() || '') !== rawQuery) {
                return;
            }

            if (control.type === 'municipality') {
                results = municipalityResults(query, control.group);
            } else if (control.type === 'province') {
                results = provinceResults(query, control.group);
            } else {
                results = postalCodeResults(rawQuery, control.group);
            }

            renderResults(control, results);
        }).fail(function () {
            showMessage(control, 'Suggerimenti non disponibili. Puoi inserire il valore manualmente.');
        });
    }

    function bindControl($input, type, group, dataUrl) {
        var menuId = 'italianAddressAutocomplete' + (++menuSequence);
        var $wrapper = $('<div class="italian-address-autocomplete"></div>');
        var $menu = $('<div class="italian-address-autocomplete-menu" role="listbox"></div>').attr('id', menuId);
        var control = {
            $input: $input,
            $menu: $menu,
            $wrapper: $wrapper,
            type: type,
            group: group,
            dataUrl: dataUrl,
            results: [],
            activeIndex: -1,
            isFocused: false,
            timer: null
        };

        $input.wrap($wrapper);
        control.$wrapper = $input.parent();
        control.$wrapper.append($menu);
        $input.attr({
            autocomplete: 'off',
            role: 'combobox',
            'aria-autocomplete': 'list',
            'aria-expanded': 'false',
            'aria-controls': menuId
        });

        $input.on('input focus', function () {
            control.isFocused = document.activeElement === this;
            window.clearTimeout(control.timer);
            control.timer = window.setTimeout(function () {
                runSearch(control);
            }, 80);
        });

        $input.on('keydown', function (event) {
            if (!control.$menu.is(':visible')) {
                return;
            }

            if (event.key === 'ArrowDown' || event.keyCode === 40) {
                event.preventDefault();
                setActive(control, control.activeIndex + 1);
            } else if (event.key === 'ArrowUp' || event.keyCode === 38) {
                event.preventDefault();
                setActive(control, control.activeIndex - 1);
            } else if ((event.key === 'Enter' || event.keyCode === 13) && control.activeIndex >= 0) {
                event.preventDefault();
                selectResult(control, control.results[control.activeIndex]);
            } else if (event.key === 'Escape' || event.keyCode === 27) {
                hideMenu(control);
            }
        });

        $input.on('blur', function () {
            control.isFocused = false;
            window.setTimeout(function () {
                hideMenu(control);
            }, 150);
        });

        $menu.on('mouseenter', '.italian-address-autocomplete-item', function () {
            setActive(control, parseInt($(this).attr('data-index'), 10));
        });

        $menu.on('mousedown', '.italian-address-autocomplete-item', function (event) {
            event.preventDefault();
            selectResult(control, control.results[parseInt($(this).attr('data-index'), 10)]);
        });

        controls.push(control);
    }

    function init(options) {
        options = options || {};

        $.each(options.groups || [], function (_, selectors) {
            var group = {
                $municipality: $(selectors.municipality || []),
                $province: $(selectors.province || []),
                $postalCode: $(selectors.postalCode || [])
            };

            if (group.$municipality.length) {
                bindControl(group.$municipality, 'municipality', group, options.dataUrl);
            }
            if (group.$province.length) {
                bindControl(group.$province, 'province', group, options.dataUrl);
            }
            if (group.$postalCode.length) {
                bindControl(group.$postalCode, 'postalCode', group, options.dataUrl);
            }
        });

        $(document).on('mousedown.italianAddressAutocomplete', function (event) {
            if ($(event.target).closest('.italian-address-autocomplete').length) {
                return;
            }

            $.each(controls, function (_, control) {
                hideMenu(control);
            });
        });

        $(window).on('resize.italianAddressAutocomplete', function () {
            $.each(controls, function (_, control) {
                var inputCenter = control.$input.offset().left + (control.$input.outerWidth() / 2);
                control.$wrapper.toggleClass('is-right-aligned', inputCenter > ($(window).width() / 2));
            });
        }).triggerHandler('resize.italianAddressAutocomplete');

        if (options.dataUrl) {
            window.setTimeout(function () {
                loadData(options.dataUrl);
            }, 500);
        }
    }

    window.ItalianAddressAutocomplete = {
        init: init
    };
})(window, document, window.jQuery);
