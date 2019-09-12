var __decorate = (this && this.__decorate) || function (decorators, target, key, desc) {
    var c = arguments.length, r = c < 3 ? target : desc === null ? desc = Object.getOwnPropertyDescriptor(target, key) : desc, d;
    if (typeof Reflect === "object" && typeof Reflect.decorate === "function") r = Reflect.decorate(decorators, target, key, desc);
    else for (var i = decorators.length - 1; i >= 0; i--) if (d = decorators[i]) r = (c < 3 ? d(r) : c > 3 ? d(target, key, r) : d(target, key)) || r;
    return c > 3 && r && Object.defineProperty(target, key, r), r;
};
import { Injectable } from '@angular/core';
import { of } from 'rxjs';
import { tap, delay } from 'rxjs/operators';
var AuthService = /** @class */ (function () {
    function AuthService() {
        this.isLoggedIn = false;
    }
    AuthService.prototype.login = function () {
        var _this = this;
        return of(true).pipe(delay(1000), tap(function (val) { return _this.isLoggedIn = true; }));
    };
    AuthService.prototype.logout = function () {
        this.isLoggedIn = false;
    };
    AuthService = __decorate([
        Injectable({
            providedIn: 'root',
        })
    ], AuthService);
    return AuthService;
}());
export { AuthService };
/*
Copyright Google LLC. All Rights Reserved.
Use of this source code is governed by an MIT-style license that
can be found in the LICENSE file at http://angular.io/license
*/ 
//# sourceMappingURL=auth.service.js.map