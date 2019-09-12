/**
 * DevExtreme (localization/core.js)
 * Version: 19.1.5
 * Build date: Tue Jul 30 2019
 *
 * Copyright (c) 2012 - 2019 Developer Express Inc. ALL RIGHTS RESERVED
 * Read about DevExtreme licensing here: https://js.devexpress.com/Licensing/
 */
"use strict";
var dependencyInjector = require("../core/utils/dependency_injector");
module.exports = dependencyInjector({
    locale: function() {
        var currentLocale = "en";
        return function(locale) {
            if (!locale) {
                return currentLocale
            }
            currentLocale = locale
        }
    }()
});
