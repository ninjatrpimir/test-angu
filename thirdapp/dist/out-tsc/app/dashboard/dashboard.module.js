var __decorate = (this && this.__decorate) || function (decorators, target, key, desc) {
    var c = arguments.length, r = c < 3 ? target : desc === null ? desc = Object.getOwnPropertyDescriptor(target, key) : desc, d;
    if (typeof Reflect === "object" && typeof Reflect.decorate === "function") r = Reflect.decorate(decorators, target, key, desc);
    else for (var i = decorators.length - 1; i >= 0; i--) if (d = decorators[i]) r = (c < 3 ? d(r) : c > 3 ? d(target, key, r) : d(target, key)) || r;
    return c > 3 && r && Object.defineProperty(target, key, r), r;
};
import { NgModule } from '@angular/core';
import { CommonModule } from '@angular/common';
// import { FormsModule }    from '@angular/forms';
import { DashboardComponent } from './dashboard/dashboard.component';
import { DashboardCommissionComponent } from './dashboard-commission/dashboard-commission.component';
import { DashboardRoutingModule } from './dashboard-routing.module';
// import { HeroListComponent }    from './hero-list/hero-list.component';
// import { HeroDetailComponent }  from './hero-detail/hero-detail.component';
var DashboardModule = /** @class */ (function () {
    function DashboardModule() {
    }
    DashboardModule = __decorate([
        NgModule({
            imports: [
                CommonModule,
                // FormsModule,
                DashboardRoutingModule
            ],
            declarations: [
                DashboardComponent,
                DashboardCommissionComponent
            ]
        })
    ], DashboardModule);
    return DashboardModule;
}());
export { DashboardModule };
/*
Copyright Google LLC. All Rights Reserved.
Use of this source code is governed by an MIT-style license that
can be found in the LICENSE file at http://angular.io/license
*/ 
//# sourceMappingURL=dashboard.module.js.map