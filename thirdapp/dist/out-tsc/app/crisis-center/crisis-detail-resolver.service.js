var __decorate = (this && this.__decorate) || function (decorators, target, key, desc) {
    var c = arguments.length, r = c < 3 ? target : desc === null ? desc = Object.getOwnPropertyDescriptor(target, key) : desc, d;
    if (typeof Reflect === "object" && typeof Reflect.decorate === "function") r = Reflect.decorate(decorators, target, key, desc);
    else for (var i = decorators.length - 1; i >= 0; i--) if (d = decorators[i]) r = (c < 3 ? d(r) : c > 3 ? d(target, key, r) : d(target, key)) || r;
    return c > 3 && r && Object.defineProperty(target, key, r), r;
};
var __metadata = (this && this.__metadata) || function (k, v) {
    if (typeof Reflect === "object" && typeof Reflect.metadata === "function") return Reflect.metadata(k, v);
};
import { Injectable } from '@angular/core';
import { Router } from '@angular/router';
import { of, EMPTY } from 'rxjs';
import { mergeMap, take } from 'rxjs/operators';
import { CrisisService } from './crisis.service';
var CrisisDetailResolverService = /** @class */ (function () {
    function CrisisDetailResolverService(cs, router) {
        this.cs = cs;
        this.router = router;
    }
    CrisisDetailResolverService.prototype.resolve = function (route, state) {
        var _this = this;
        var id = route.paramMap.get('id');
        return this.cs.getCrisis(id).pipe(take(1), mergeMap(function (crisis) {
            if (crisis) {
                return of(crisis);
            }
            else { // id not found
                _this.router.navigate(['/crisis-center']);
                return EMPTY;
            }
        }));
    };
    CrisisDetailResolverService = __decorate([
        Injectable({
            providedIn: 'root',
        }),
        __metadata("design:paramtypes", [CrisisService, Router])
    ], CrisisDetailResolverService);
    return CrisisDetailResolverService;
}());
export { CrisisDetailResolverService };
/*
Copyright Google LLC. All Rights Reserved.
Use of this source code is governed by an MIT-style license that
can be found in the LICENSE file at http://angular.io/license
*/ 
//# sourceMappingURL=crisis-detail-resolver.service.js.map