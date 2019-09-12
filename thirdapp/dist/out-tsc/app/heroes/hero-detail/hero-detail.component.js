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
import { Router, ActivatedRoute } from '@angular/router';
import { HeroService } from '../hero.service';
var HeroDetailComponent = /** @class */ (function () {
    function HeroDetailComponent(route, router, service) {
        this.route = route;
        this.router = router;
        this.service = service;
    }
    HeroDetailComponent.prototype.ngOnInit = function () {
        var _this = this;
        this.hero$ = this.route.paramMap.pipe(switchMap(function (params) {
            return _this.service.getHero(params.get('id'));
        }));
    };
    HeroDetailComponent.prototype.gotoHeroes = function (hero) {
        var heroId = hero ? hero.id : null;
        // Pass along the hero id if available
        // so that the HeroList component can select that hero.
        // Include a junk 'foo' property for fun.
        this.router.navigate(['/superheroes', { id: heroId, foo: 'foo' }]);
    };
    HeroDetailComponent = __decorate([
        Component({
            selector: 'app-hero-detail',
            templateUrl: './hero-detail.component.html',
            styleUrls: ['./hero-detail.component.css']
        }),
        __metadata("design:paramtypes", [ActivatedRoute,
            Router,
            HeroService])
    ], HeroDetailComponent);
    return HeroDetailComponent;
}());
export { HeroDetailComponent };
/*
Copyright Google LLC. All Rights Reserved.
Use of this source code is governed by an MIT-style license that
can be found in the LICENSE file at http://angular.io/license
*/ 
//# sourceMappingURL=hero-detail.component.js.map