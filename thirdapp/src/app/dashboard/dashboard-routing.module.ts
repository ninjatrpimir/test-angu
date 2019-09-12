import { NgModule }             from '@angular/core';
import { RouterModule, Routes } from '@angular/router';

import { DashboardComponent }              from './dashboard/dashboard.component';
import { DashboardCommissionComponent }    from './dashboard-commission/dashboard-commission.component';


const commissionRoutes: Routes = [
  { path: '', redirectTo: '/dashboard', pathMatch: 'full' },
  { path: 'dashboard',  component: DashboardComponent },
  { path: 'dashboard-commission',  component: DashboardCommissionComponent },
  { path: '**', redirectTo: 'PageNotFoundComponent' },

  // { path: 'dashboard',  component: DashboardComponent },
  // // { path: 'dashboard',  component: DashboardComponent, data: { animation: 'heroes' } },
  // { path: 'dashboard-commission',  component: DashboardCommissionComponent, data: { animation: 'heroes' } },
  // { path: '**', redirectTo: 'PageNotFoundComponent' },
];

@NgModule({
  imports: [
    RouterModule.forChild(commissionRoutes)
  ],
  exports: [
    RouterModule
  ]
})
export class DashboardRoutingModule { }


/*
Copyright Google LLC. All Rights Reserved.
Use of this source code is governed by an MIT-style license that
can be found in the LICENSE file at http://angular.io/license
*/