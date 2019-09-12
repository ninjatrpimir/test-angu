import { NgModule }       from '@angular/core';
import { CommonModule }   from '@angular/common';
// import { FormsModule }    from '@angular/forms';

import { DashboardComponent }              from './dashboard/dashboard.component';
import { DashboardCommissionComponent }    from './dashboard-commission/dashboard-commission.component';
import { DashboardRoutingModule }          from './dashboard-routing.module';

// import { HeroListComponent }    from './hero-list/hero-list.component';
// import { HeroDetailComponent }  from './hero-detail/hero-detail.component';



@NgModule({
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
export class DashboardModule {}


/*
Copyright Google LLC. All Rights Reserved.
Use of this source code is governed by an MIT-style license that
can be found in the LICENSE file at http://angular.io/license
*/