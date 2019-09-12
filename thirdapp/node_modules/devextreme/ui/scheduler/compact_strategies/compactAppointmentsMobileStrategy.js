/**
 * DevExtreme (ui/scheduler/compact_strategies/compactAppointmentsMobileStrategy.js)
 * Version: 19.1.5
 * Build date: Tue Jul 30 2019
 *
 * Copyright (c) 2012 - 2019 Developer Express Inc. ALL RIGHTS RESERVED
 * Read about DevExtreme licensing here: https://js.devexpress.com/Licensing/
 */
"use strict";
Object.defineProperty(exports, "__esModule", {
    value: true
});
exports.CompactAppointmentsMobileStrategy = void 0;
var _createClass = function() {
    function defineProperties(target, props) {
        for (var i = 0; i < props.length; i++) {
            var descriptor = props[i];
            descriptor.enumerable = descriptor.enumerable || false;
            descriptor.configurable = true;
            if ("value" in descriptor) {
                descriptor.writable = true
            }
            Object.defineProperty(target, descriptor.key, descriptor)
        }
    }
    return function(Constructor, protoProps, staticProps) {
        if (protoProps) {
            defineProperties(Constructor.prototype, protoProps)
        }
        if (staticProps) {
            defineProperties(Constructor, staticProps)
        }
        return Constructor
    }
}();
var _compactAppointmentsStrategyBase = require("./compactAppointmentsStrategyBase");
var _renderer = require("../../../core/renderer");
var _renderer2 = _interopRequireDefault(_renderer);

function _interopRequireDefault(obj) {
    return obj && obj.__esModule ? obj : {
        "default": obj
    }
}

function _classCallCheck(instance, Constructor) {
    if (!(instance instanceof Constructor)) {
        throw new TypeError("Cannot call a class as a function")
    }
}

function _possibleConstructorReturn(self, call) {
    if (!self) {
        throw new ReferenceError("this hasn't been initialised - super() hasn't been called")
    }
    return call && ("object" === typeof call || "function" === typeof call) ? call : self
}

function _inherits(subClass, superClass) {
    if ("function" !== typeof superClass && null !== superClass) {
        throw new TypeError("Super expression must either be null or a function, not " + typeof superClass)
    }
    subClass.prototype = Object.create(superClass && superClass.prototype, {
        constructor: {
            value: subClass,
            enumerable: false,
            writable: true,
            configurable: true
        }
    });
    if (superClass) {
        Object.setPrototypeOf ? Object.setPrototypeOf(subClass, superClass) : subClass.__proto__ = superClass
    }
}
var appointmentClassName = "dx-scheduler-appointment-collector dx-scheduler-appointment-collector-compact";
var CompactAppointmentsMobileStrategy = exports.CompactAppointmentsMobileStrategy = function(_CompactAppointmentsS) {
    _inherits(CompactAppointmentsMobileStrategy, _CompactAppointmentsS);

    function CompactAppointmentsMobileStrategy() {
        _classCallCheck(this, CompactAppointmentsMobileStrategy);
        return _possibleConstructorReturn(this, (CompactAppointmentsMobileStrategy.__proto__ || Object.getPrototypeOf(CompactAppointmentsMobileStrategy)).apply(this, arguments))
    }
    _createClass(CompactAppointmentsMobileStrategy, [{
        key: "renderCore",
        value: function(options) {
            var _this2 = this;
            var compactAppointment = (0, _renderer2.default)("<div>").addClass(appointmentClassName).html(options.items.data.length).appendTo(options.$container);
            this.setPosition(compactAppointment, options.coordinates);
            compactAppointment.click(function() {
                var dataItems = options.items.data.map(function(data) {
                    return {
                        data: data,
                        currentData: data,
                        $appointment: null
                    }
                });
                _this2.instance._appointmentTooltip.show(dataItems)
            });
            return compactAppointment
        }
    }]);
    return CompactAppointmentsMobileStrategy
}(_compactAppointmentsStrategyBase.CompactAppointmentsStrategyBase);
