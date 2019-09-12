var __decorate = (this && this.__decorate) || function (decorators, target, key, desc) {
    var c = arguments.length, r = c < 3 ? target : desc === null ? desc = Object.getOwnPropertyDescriptor(target, key) : desc, d;
    if (typeof Reflect === "object" && typeof Reflect.decorate === "function") r = Reflect.decorate(decorators, target, key, desc);
    else for (var i = decorators.length - 1; i >= 0; i--) if (d = decorators[i]) r = (c < 3 ? d(r) : c > 3 ? d(target, key, r) : d(target, key)) || r;
    return c > 3 && r && Object.defineProperty(target, key, r), r;
};
import { NgModule } from '@angular/core';
import { RouterModule } from '@angular/router';
import { HeroListComponent } from './hero-list/hero-list.component';
import { HeroDetailComponent } from './hero-detail/hero-detail.component';
var heroesRoutes = [
    { path: '', redirectTo: '/dashboard' },
    { path: 'index', redirectTo: '/dashboard' },
    { path: 'dashboard', component: HeroListComponent, data: { animation: 'heroes' } },
    { path: 'heroes', redirectTo: '/superheroes' },
    { path: 'hero/:id', redirectTo: '/superhero/:id' },
    { path: 'superheroes', component: HeroListComponent, data: { animation: 'heroes' } },
    { path: 'superhero/:id', component: HeroDetailComponent, data: { animation: 'hero' } }
];
var HeroesRoutingModule = /** @class */ (function () {
    function HeroesRoutingModule() {
    }
    HeroesRoutingModule = __decorate([
        NgModule({
            imports: [
                RouterModule.forChild(heroesRoutes)
            ],
            exports: [
                RouterModule
            ]
        })
    ], HeroesRoutingModule);
    return HeroesRoutingModule;
}());
export { HeroesRoutingModule };
/*
Copyright Google LLC. All Rights Reserved.
Use of this source code is governed by an MIT-style license that
can be found in the LICENSE file at http://angular.io/license
*/ 
//# sourceMappingURL=heroes-routing.module.js.map