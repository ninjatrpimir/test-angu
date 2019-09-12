var __decorate = (this && this.__decorate) || function (decorators, target, key, desc) {
    var c = arguments.length, r = c < 3 ? target : desc === null ? desc = Object.getOwnPropertyDescriptor(target, key) : desc, d;
    if (typeof Reflect === "object" && typeof Reflect.decorate === "function") r = Reflect.decorate(decorators, target, key, desc);
    else for (var i = decorators.length - 1; i >= 0; i--) if (d = decorators[i]) r = (c < 3 ? d(r) : c > 3 ? d(target, key, r) : d(target, key)) || r;
    return c > 3 && r && Object.defineProperty(target, key, r), r;
};
var __metadata = (this && this.__metadata) || function (k, v) {
    if (typeof Reflect === "object" && typeof Reflect.metadata === "function") return Reflect.metadata(k, v);
};
import { switchMap } from 'rxjs/operators';
import { Component } from '@angular/core';
import { ActivatedRoute } from '@angular/router';
import { HeroService } from '../dashboard.service';
var HeroListComponent = /** @class */ (function () {
    function HeroListComponent(service, route) {
        this.service = service;
        this.route = route;
    }
    HeroListComponent.prototype.ngOnInit = function () {
        var _this = this;
        this.heroes$ = this.route.paramMap.pipe(switchMap(function (params) {
            // (+) before `params.get()` turns the string into a number
            _this.selectedId = +params.get('id');
            return _this.service.getHeroes();
        }));
    };
    var _a;
    HeroListComponent = __decorate([
        Component({
            selector: 'app-hero-list',
            templateUrl: './hero-list.component.html',
            styleUrls: ['./hero-list.component.css']
        }),
        __metadata("design:paramtypes", [typeof (_a = typeof HeroService !== "undefined" && HeroService) === "function" ? _a : Object, ActivatedRoute])
    ], HeroListComponent);
    return HeroListComponent;
}());
export { HeroListComponent };
/*
Copyright Google LLC. All Rights Reserved.
Use of this source code is governed by an MIT-style license that
can be found in the LICENSE file at http://angular.io/license
*/ 
//# sourceMappingURL=hero-list.component.js.map