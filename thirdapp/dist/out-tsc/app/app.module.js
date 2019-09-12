var __decorate = (this && this.__decorate) || function (decorators, target, key, desc) {
    var c = arguments.length, r = c < 3 ? target : desc === null ? desc = Object.getOwnPropertyDescriptor(target, key) : desc, d;
    if (typeof Reflect === "object" && typeof Reflect.decorate === "function") r = Reflect.decorate(decorators, target, key, desc);
    else for (var i = decorators.length - 1; i >= 0; i--) if (d = decorators[i]) r = (c < 3 ? d(r) : c > 3 ? d(target, key, r) : d(target, key)) || r;
    return c > 3 && r && Object.defineProperty(target, key, r), r;
};
var __metadata = (this && this.__metadata) || function (k, v) {
    if (typeof Reflect === "object" && typeof Reflect.metadata === "function") return Reflect.metadata(k, v);
};
import { NgModule } from '@angular/core';
import { BrowserModule } from '@angular/platform-browser';
import { FormsModule } from '@angular/forms';
import { BrowserAnimationsModule } from '@angular/platform-browser/animations';
import { Router } from '@angular/router';
import { AppComponent } from './app.component';
import { PageNotFoundComponent } from './page-not-found/page-not-found.component';
import { ComposeMessageComponent } from './compose-message/compose-message.component';
import { AppRoutingModule } from './app-routing.module';
import { HeroesModule } from './heroes/heroes.module';
import { AuthModule } from './auth/auth.module';
var AppModule = /** @class */ (function () {
    // Diagnostic only: inspect router configuration
    function AppModule(router) {
        // Use a custom replacer to display function names in the route configs
        // const replacer = (key, value) => (typeof value === 'function') ? value.name : value;
        // console.log('Routes: ', JSON.stringify(router.config, replacer, 2));
    }
    AppModule = __decorate([
        NgModule({
            imports: [
                BrowserModule,
                BrowserAnimationsModule,
                FormsModule,
                HeroesModule,
                AuthModule,
                AppRoutingModule,
            ],
            declarations: [
                AppComponent,
                ComposeMessageComponent,
                PageNotFoundComponent
            ],
            bootstrap: [AppComponent]
        }),
        __metadata("design:paramtypes", [Router])
    ], AppModule);
    return AppModule;
}());
export { AppModule };
/*
Copyright Google LLC. All Rights Reserved.
Use of this source code is governed by an MIT-style license that
can be found in the LICENSE file at http://angular.io/license
*/ 
//# sourceMappingURL=app.module.js.map