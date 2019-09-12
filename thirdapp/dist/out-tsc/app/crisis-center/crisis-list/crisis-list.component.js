var __decorate = (this && this.__decorate) || function (decorators, target, key, desc) {
    var c = arguments.length, r = c < 3 ? target : desc === null ? desc = Object.getOwnPropertyDescriptor(target, key) : desc, d;
    if (typeof Reflect === "object" && typeof Reflect.decorate === "function") r = Reflect.decorate(decorators, target, key, desc);
    else for (var i = decorators.length - 1; i >= 0; i--) if (d = decorators[i]) r = (c < 3 ? d(r) : c > 3 ? d(target, key, r) : d(target, key)) || r;
    return c > 3 && r && Object.defineProperty(target, key, r), r;
};
var __metadata = (this && this.__metadata) || function (k, v) {
    if (typeof Reflect === "object" && typeof Reflect.metadata === "function") return Reflect.metadata(k, v);
};
import { Component } from '@angular/core';
import { ActivatedRoute } from '@angular/router';
import { CrisisService } from '../crisis.service';
import { switchMap } from 'rxjs/operators';
var CrisisListComponent = /** @class */ (function () {
    function CrisisListComponent(service, route) {
        this.service = service;
        this.route = route;
    }
    CrisisListComponent.prototype.ngOnInit = function () {
        var _this = this;
        this.crises$ = this.route.paramMap.pipe(switchMap(function (params) {
            _this.selectedId = +params.get('id');
            return _this.service.getCrises();
        }));
    };
    CrisisListComponent = __decorate([
        Component({
            selector: 'app-crisis-list',
            templateUrl: './crisis-list.component.html',
            styleUrls: ['./crisis-list.component.css']
        }),
        __metadata("design:paramtypes", [CrisisService,
            ActivatedRoute])
    ], CrisisListComponent);
    return CrisisListComponent;
}());
export { CrisisListComponent };
/*
Copyright Google LLC. All Rights Reserved.
Use of this source code is governed by an MIT-style license that
can be found in the LICENSE file at http://angular.io/license
*/ 
//# sourceMappingURL=crisis-list.component.js.map