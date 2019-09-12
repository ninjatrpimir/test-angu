/**
 * DevExtreme (exporter/exceljs/exportDataGrid.js)
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
exports.default = exportDataGrid;
var _type = require("../../core/utils/type");
var _type2 = _interopRequireDefault(_type);

function _interopRequireDefault(obj) {
    return obj && obj.__esModule ? obj : {
        "default": obj
    }
}

function exportDataGrid(options) {
    if (!_type2.default.isDefined(options)) {
        return
    }
    var customizeCell = options.customizeCell,
        component = options.component,
        worksheet = options.worksheet,
        _options$topLeftCell = options.topLeftCell,
        topLeftCell = void 0 === _options$topLeftCell ? {
            row: 1,
            column: 1
        } : _options$topLeftCell,
        excelFilterEnabled = options.excelFilterEnabled;
    worksheet.properties.outlineProperties = {
        summaryBelow: false,
        summaryRight: false
    };
    var result = {
        from: {
            row: topLeftCell.row,
            column: topLeftCell.column
        },
        to: {
            row: topLeftCell.row,
            column: topLeftCell.column
        }
    };
    var dataProvider = component.getDataProvider();
    return new Promise(function(resolve) {
        dataProvider.ready().done(function() {
            var columns = dataProvider.getColumns();
            var headerRowCount = dataProvider.getHeaderRowCount();
            var dataRowsCount = dataProvider.getRowsCount();
            for (var rowIndex = 0; rowIndex < dataRowsCount; rowIndex++) {
                var row = worksheet.getRow(result.from.row + rowIndex);
                _exportRow(rowIndex, columns.length, row, result.from.column, dataProvider, customizeCell);
                if (rowIndex >= headerRowCount) {
                    row.outlineLevel = dataProvider.getGroupLevel(rowIndex)
                }
                if (rowIndex >= 1) {
                    result.to.row++
                }
            }
            result.to.column += columns.length > 0 ? columns.length - 1 : 0;
            if (true === excelFilterEnabled) {
                if (dataRowsCount > 0) {
                    worksheet.autoFilter = result
                }
                worksheet.views = [{
                    state: "frozen",
                    ySplit: result.from.row + dataProvider.getFrozenArea().y - 1
                }]
            }
            resolve(result)
        })
    })
}

function _exportRow(rowIndex, cellCount, row, startColumnIndex, dataProvider, customizeCell) {
    for (var cellIndex = 0; cellIndex < cellCount; cellIndex++) {
        var cellData = dataProvider.getCellData(rowIndex, cellIndex, true);
        var cell = row.getCell(startColumnIndex + cellIndex);
        cell.value = cellData.value;
        if (_type2.default.isDefined(customizeCell)) {
            customizeCell({
                cell: cell,
                gridCell: {
                    column: cellData.cellSourceData.column,
                    rowType: cellData.cellSourceData.rowType,
                    data: cellData.cellSourceData.data,
                    value: cellData.value,
                    groupIndex: cellData.cellSourceData.groupIndex
                }
            })
        }
    }
}
