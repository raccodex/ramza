const funcs = require('../functions/functions')
const compiledTemplates = require('../compiledTemplates/compiledTemplates')
const socketEvents = require('../events/events')
const { Sequelize, Op, DataTypes } = require("sequelize");
const striptags = require('striptags');
const moment = require("moment")

const OnUserLoggedinController = async (ctx, data, io,socket) => {
  let user_id = ctx.userHashUserId[data.from_id]
  let followers = await ctx.wo_followers.findAll({
      attributes: ["following_id"],
      where: {
          follower_id: user_id,
          following_id: {
              [Op.not]: user_id
          }
      },
      raw: true
  })
  for (let follow of followers) {
      await io.to(follow.following_id).emit("on_user_loggedin", { user_id: user_id })
  }
};

module.exports = { OnUserLoggedinController };