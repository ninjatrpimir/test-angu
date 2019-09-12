var __decorate = (this && this.__decorate) || function (decorators, target, key, desc) {
    var c = arguments.length, r = c < 3 ? target : desc === null ? desc = Object.getOwnPropertyDescriptor(target, key) : desc, d;
    if (typeof Reflect === "object" && typeof Reflect.decorate === "function") r = Reflect.decorate(decorators, target, key, desc);
    else for (var i = decorators.length - 1; i >= 0; i--) if (d = decorators[i]) r = (c < 3 ? d(r) : c > 3 ? d(target, key, r) : d(target, key)) || r;
    return c > 3 && r && Object.defineProperty(target, key, r), r;
};
import { NgModule } from '@angular/core';
import { RouterModule } from '@angular/router';
import { CrisisCenterHomeComponent } from './crisis-center-home/crisis-center-home.component';
import { CrisisListComponent } from './crisis-list/crisis-list.component';
import { CrisisCenterComponent } from './crisis-center/crisis-center.component';
import { CrisisDetailComponent } from './crisis-detail/crisis-detail.component';
import { CanDeactivateGuard } from '../can-deactivate.guard';
import { CrisisDetailResolverService } from './crisis-detail-resolver.service';
var crisisCenterRoutes = [
    {
        path: '',
        component: CrisisCenterComponent,
        children: [
            {
                path: '',
                component: CrisisListComponent,
                children: [
                    {
                        path: ':id',
                        component: CrisisDetailComponent,
                        canDeactivate: [CanDeactivateGuard],
                        resolve: {
                            crisis: CrisisDetailResolverService
                        }
                    },
                    {
                        path: '',
                        component: CrisisCenterHomeComponent
                    }
                ]
            }
        ]
    }
];
var CrisisCenterRoutingModule = /** @class */ (function () {
    function CrisisCenterRoutingModule() {
    }
    CrisisCenterRoutingModule = __decorate([
        NgModule({
            imports: [
                RouterModule.forChild(crisisCenterRoutes)
            ],
            exports: [
                RouterModule
            ]
        })
    ], CrisisCenterRoutingModule);
    return CrisisCenterRoutingModule;
}());
export { CrisisCenterRoutingModule };
/*
Copyright Google LLC. All Rights Reserved.
Use of this source code is governed by an MIT-style license that
can be found in the LICENSE file at http://angular.io/license
*/ 
//# sourceMappingURL=crisis-center-routing.module.js.map