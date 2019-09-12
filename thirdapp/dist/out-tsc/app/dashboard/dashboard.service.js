var __decorate = (this && this.__decorate) || function (decorators, target, key, desc) {
    var c = arguments.length, r = c < 3 ? target : desc === null ? desc = Object.getOwnPropertyDescriptor(target, key) : desc, d;
    if (typeof Reflect === "object" && typeof Reflect.decorate === "function") r = Reflect.decorate(decorators, target, key, desc);
    else for (var i = decorators.length - 1; i >= 0; i--) if (d = decorators[i]) r = (c < 3 ? d(r) : c > 3 ? d(target, key, r) : d(target, key)) || r;
    return c > 3 && r && Object.defineProperty(target, key, r), r;
};
var __metadata = (this && this.__metadata) || function (k, v) {
    if (typeof Reflect === "object" && typeof Reflect.metadata === "function") return Reflect.metadata(k, v);
};
import { Injectable } from '@angular/core';
import { of } from 'rxjs';
import { map } from 'rxjs/operators';
import { COMMISSIONS } from './mock-commission';
import { MessageService } from '../message.service';
var DashboardService = /** @class */ (function () {
    function DashboardService(messageService) {
        this.messageService = messageService;
    }
    DashboardService.prototype.getCommissions = function () {
        // TODO: send the message _after_ fetching the commissions
        this.messageService.add('CommissionService: fetched commissions');
        return of(COMMISSIONS);
    };
    DashboardService.prototype.getCommission = function (id) {
        // return this.getCommissions();
        return this.getCommissions().pipe(
        // (+) before `id` turns the string into a number
        map(function (commissions) { return commissions.find(function (commission) { return commission.id === +id; }); }));
    };
    DashboardService = __decorate([
        Injectable({
            providedIn: 'root',
        }),
        __metadata("design:paramtypes", [MessageService])
    ], DashboardService);
    return DashboardService;
}());
export { DashboardService };
/*
Copyright Google LLC. All Rights Reserved.
Use of this source code is governed by an MIT-style license that
can be found in the LICENSE file at http://angular.io/license
*/ 
//# sourceMappingURL=dashboard.service.js.map