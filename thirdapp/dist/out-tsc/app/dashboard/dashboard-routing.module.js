var __decorate = (this && this.__decorate) || function (decorators, target, key, desc) {
    var c = arguments.length, r = c < 3 ? target : desc === null ? desc = Object.getOwnPropertyDescriptor(target, key) : desc, d;
    if (typeof Reflect === "object" && typeof Reflect.decorate === "function") r = Reflect.decorate(decorators, target, key, desc);
    else for (var i = decorators.length - 1; i >= 0; i--) if (d = decorators[i]) r = (c < 3 ? d(r) : c > 3 ? d(target, key, r) : d(target, key)) || r;
    return c > 3 && r && Object.defineProperty(target, key, r), r;
};
import { NgModule } from '@angular/core';
import { RouterModule } from '@angular/router';
import { DashboardComponent } from './dashboard/dashboard.component';
import { DashboardCommissionComponent } from './dashboard-commission/dashboard-commission.component';
var commissionRoutes = [
    { path: '', redirectTo: '/dashboard' },
    { path: 'index', redirectTo: '/dashboard' },
    { path: 'dashboard', component: DashboardComponent },
    { path: 'dashboard-commission', component: DashboardCommissionComponent },
    { path: '**', redirectTo: 'PageNotFoundComponent' },
];
var DashboardRoutingModule = /** @class */ (function () {
    function DashboardRoutingModule() {
    }
    DashboardRoutingModule = __decorate([
        NgModule({
            imports: [
                RouterModule.forChild(commissionRoutes)
            ],
            exports: [
                RouterModule
            ]
        })
    ], DashboardRoutingModule);
    return DashboardRoutingModule;
}());
export { DashboardRoutingModule };
/*
Copyright Google LLC. All Rights Reserved.
Use of this source code is governed by an MIT-style license that
can be found in the LICENSE file at http://angular.io/license
*/ 
//# sourceMappingURL=dashboard-routing.module.js.map